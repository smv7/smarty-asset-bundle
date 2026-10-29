<?php

/**
 * This file is part of the CMS Donate Engine package.
 * @copyright (c) 2025-2026 Vital Smereka (https://cde.by)
 */

namespace SmartyBundler;

class AssetBundle
{
    private array $config;
    private PreloadManager $preloadManager;
    private CacheManager $cacheManager;
    private string $documentRoot;
    private string $resourceBasePath;
    private string $documentUrl;
    private array $warnedFiles = [];

    public function __construct(array $config = [])
    {
        $defaults = [
            'cache_dir' => null,
            'cache_url' => null,
            'document_root' => null,
            'enable_apcu' => extension_loaded('apcu'),
        ];

        $this->config = array_merge($defaults, $config);

        if ($this->config['cache_dir'] === null || $this->config['cache_url'] === null || $this->config['document_root'] === null) {
            throw new \InvalidArgumentException("AssetBundle requires 'cache_dir', 'cache_url', and 'document_root' to be provided in config.");
        }

        $this->config['cache_dir'] = rtrim(str_replace('\\', '/', $this->config['cache_dir']), '/');
        $this->config['cache_url'] = rtrim($this->config['cache_url'], '/') . '/';
        $this->documentRoot = rtrim(str_replace('\\', '/', $this->config['document_root']), '/');
        $this->resourceBasePath = dirname($this->config['cache_dir']);
        $this->documentUrl = $this->computeDocumentUrl();

        $this->preloadManager = new PreloadManager($this->config['enable_apcu']);
        $this->cacheManager = new CacheManager($this->config['cache_dir']);
    }

    /**
     * Основной метод обработки бандла
     */
    public function processBundle(array $files, array $options = []): string
    {
        if (isset($options['content']) && $options['content'] !== '') {
            return $this->processContentBundle($options['content'], $options);
        }

        if (empty($files)) {
            return '';
        }

        $firstType = null;
        foreach ($files as $file) {
            $ext = pathinfo($file, \PATHINFO_EXTENSION);
            if (!in_array($ext, ['css', 'js'], true)) {
                trigger_error("SmartyBundle: Invalid file extension '{$ext}'", \E_USER_WARNING);
                return '';
            }
            $currentType = $ext === 'css' ? 'css' : 'js';
            if ($firstType === null) {
                $firstType = $currentType;
            } elseif ($firstType !== $currentType) {
                trigger_error('SmartyBundle: Cannot mix CSS and JS files in one bundle', \E_USER_WARNING);
                return '';
            }
        }

        $type = $options['type'] ?? $firstType;
        $absoluteFiles = array_map([$this, 'resolveFilePath'], $files);

        if (!empty($options['bundle_disable'])) {
            return $this->generateSourceFilesHtml($files, $type, array_merge($options, ['cache_buster' => time()]));
        }

        foreach ($absoluteFiles as $file) {
            if (!file_exists($file)) {
                return $this->generateSourceFilesHtml($files, $type, array_merge($options, ['cache_buster' => time()]));
            }
        }

        $cacheKey = $this->cacheManager->generateCacheKey($absoluteFiles, $options, $type);
        $cachedPath = $this->cacheManager->getCachedFilePath($cacheKey, $type, $options);

        if ($cachedPath !== null) {
            $url = $this->getResourceUrl($cachedPath);
            $html = $this->generateHtmlTag($type, $url, $options);

            if (!empty($options['preload']) && $this->preloadManager->isEnabled()) {
                $size = @filesize($cachedPath) ?: 0;
                $this->preloadManager->registerBundleForLearning($cacheKey, $type, $url, $size);
            }
            return $html;
        }

        $output = $this->generateSourceFilesHtml($files, $type, array_merge($options, ['cache_buster' => time()]));
        $this->cacheManager->scheduleCacheGeneration($absoluteFiles, $cacheKey, $type, $options);

        return $output;
    }

    /**
     * Отправляем прелоад заголовки для указанного шаблона
     */
    public function sendCachedPreloads(string $templateName = 'stock'): void
    {
        $this->preloadManager->setCurrentTemplate($templateName);
        $this->preloadManager->sendCachedPreloads();
    }

    /**
     * Генерация HTML для исходных файлов
     */
    private function generateSourceFilesHtml(array $originalFiles, string $type, array $options): string
    {
        $output = '';
        $cacheBusterValue = $options['cache_buster'] ?? null;
        foreach ($originalFiles as $originalFile) {
            $absolutePath = $this->resolveFilePath($originalFile);
            if (!file_exists($absolutePath)) {
                if (!in_array($absolutePath, $this->warnedFiles, true)) {
                    trigger_error("SmartyBundle: File not found: {$absolutePath}", E_USER_WARNING);
                    $this->warnedFiles[] = $absolutePath;
                }
                continue;
            }
            $url = $this->getResourceUrl($absolutePath);

            if ($url === '') {
                continue;
            }

            if ($cacheBusterValue !== null) {
                $delimiter = strpos($url, '?') !== false ? '&' : '?';
                $url .= $delimiter . 'cb=' . urlencode($cacheBusterValue);
            }

            $optionsForTag = $options;
            unset($optionsForTag['cache_buster']);

            $output .= $this->generateHtmlTag($type, $url, $optionsForTag);
        }
        return $output;
    }

    /**
     * Вычисляет корневой URL для document_root.
     * Отрезает от cache_url относительный путь resourceBasePath относительно document_root.
     */
    private function computeDocumentUrl(): string
    {
        $baseRel = $this->makeRelativePathFromResourceBase($this->resourceBasePath, $this->documentRoot);
        if ($baseRel === null || $baseRel === '') {
            return '/';
        }

        $cacheUrl = rtrim($this->config['cache_url'], '/');
        $suffix = '/' . ltrim($baseRel, '/');

        if (!str_ends_with($cacheUrl, $suffix)) {
            return '/';
        }

        return rtrim(substr($cacheUrl, 0, -strlen($suffix)), '/') . '/';
    }

    /**
     * Вспомогательный метод для получения URL ресурса.
     * Все файлы резолвятся относительно document_root.
     */
    private function getResourceUrl(string $absoluteResourcePath): string
    {
        $rel = $this->makeRelativePathFromResourceBase($absoluteResourcePath, $this->documentRoot);

        return $rel === null ? '' : $this->documentUrl . ltrim($rel, '/');
    }

    /**
     * Вспомогательный метод для получения относительного пути
     */
    private function makeRelativePathFromResourceBase(string $fullPath, string $basePath): ?string
    {
        $realFull = realpath($fullPath);
        $realBase = realpath($basePath);

        if ($realFull === false || $realBase === false) {
            $realFull = $fullPath;
            $realBase = $basePath;
        }

        $realFull = str_replace('\\', '/', $realFull);
        $realBase = str_replace('\\', '/', $realBase);

        $baseWithSlash = rtrim($realBase, '/') . '/';
        if (str_starts_with($realFull, $baseWithSlash)) {
            return substr($realFull, strlen($baseWithSlash));
        }

        if ($realFull === rtrim($realBase, '/')) {
            return basename($realFull);
        }

        return null;
    }

    /**
     * Генерация HTML тега
     */
    private function generateHtmlTag(string $type, string $url, array $options): string
    {
        if ($type === 'css') {
            $attributes = [];
            if (isset($options['media'])) {
                $attributes[] = 'media="' . htmlspecialchars($options['media']) . '"';
            }
            if (isset($options['onload'])) {
                $attributes[] = 'onload="' . htmlspecialchars($options['onload']) . '"';
            }
            $attrString = !empty($attributes) ? ' ' . implode(' ', $attributes) : '';
            return '<link rel="stylesheet" href="' . htmlspecialchars($url) . '"' . $attrString . '>' . "\n";
        }

        if ($type === 'js') {
            $attributes = [];
            if (isset($options['defer']) && $options['defer']) {
                $attributes[] = 'defer';
            }
            if (isset($options['async']) && $options['async']) {
                $attributes[] = 'async';
            }
            $attrString = !empty($attributes) ? ' ' . implode(' ', $attributes) : '';
            return '<script src="' . htmlspecialchars($url) . '"' . $attrString . '></script>' . "\n";
        }

        return '';
    }

    /**
     * Разрешение и санитизация пути к файлу
     */
    private function resolveFilePath(string $file): string
    {
        $file = str_replace(['../', '..\\', "\0"], '', $file);
        $file = str_replace('\\', '/', $file);
        if (str_starts_with($file, '/') || preg_match('/^[a-zA-Z]:\//', $file)) {
            return $file;
        }

        return $this->documentRoot . '/' . ltrim($file, '/');
    }

    /**
     * Определяет тип контента (CSS или JS) по его содержимому
     */
    private function detectContentType(string $content): string
    {
        $content = trim($content);

        $jsPatterns = [
            '/^\s*<script/i',
            '/function\s*\(/',
            '/=>/',
            '/\$\s*\(/',
            '/\.addEventListener/',
            '/console\.(log|debug|error)/',
            '/document\./',
            '/window\./',
            '/;\s*$/m',
            '/\{\s*[\w\s]*:\s*[\w\s]*\}/'
        ];

        foreach ($jsPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return 'js';
            }
        }

        $cssPatterns = [
            '/^\s*[.#\[a-z][^{]*\{[^}]*\}/ims',
            '/@media/',
            '/@keyframes/',
            '/@import/',
            '/@font-face/',
            '/:\s*(rgb|hsl|#[0-9a-f])/i',
            '/\b(margin|padding|color|width|height|display)\s*:/i'
        ];

        foreach ($cssPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return 'css';
            }
        }

        return 'css';
    }

    /**
     * Обрабатывает строковый бандл (content)
     */
    private function processContentBundle(string $content, array $options): string
    {
        $type = $options['type'] ?? $this->detectContentType($content);

        if (!in_array($type, ['css', 'js'])) {
            return $this->wrapInlineContent($content, 'css', $options);
        }

        $content = $this->sanitizeContent($content, $type);

        $cacheKey = $this->cacheManager->generateContentCacheKey($content, $options, $type);
        $cachedPath = $this->cacheManager->getCachedFilePath($cacheKey, $type, $options);

        if ($cachedPath !== null) {
            $url = $this->getResourceUrl($cachedPath);
            $html = $this->generateHtmlTag($type, $url, $options);

            if (!empty($options['preload']) && $this->preloadManager->isEnabled()) {
                $size = @filesize($cachedPath) ?: 0;
                $this->preloadManager->registerBundleForLearning($cacheKey, $type, $url, $size);
            }

            return $html;
        }

        $this->cacheManager->scheduleCacheGeneration($content, $cacheKey, $type, $options);
        return $this->wrapInlineContent($content, $type, $options);
    }

    /**
     * Санитизирует контент для безопасного встраивания
     */
    private function sanitizeContent(string $content, string $type): string
    {
        $replacements = [
            "\0" => '',
            "\r" => '',
            "\t" => ' ',
        ];

        $content = strtr($content, $replacements);

        if ($type === 'css') {
            $dangerousPatterns = [
                '/expression\s*\(/i' => '',
                '/javascript\s*:/i' => '',
                '/vbscript\s*:/i' => '',
                '/@import\s+url/i' => '',
                '/behavior\s*:/i' => '',
                '/binding\s*:/i' => '',
                '/-moz-binding/i' => '',
            ];

            $content = preg_replace(array_keys($dangerousPatterns), array_values($dangerousPatterns), $content);
        }

        if ($type === 'js') {
            $content = str_replace('</script>', '<\/script>', $content);
        }

        return trim($content);
    }

    /**
     * Обертывает строковый контент в HTML-тег
     */
    private function wrapInlineContent(string $content, string $type, array $options): string
    {
        if ($type === 'css') {
            $attributes = [];
            if (isset($options['media'])) {
                $attributes[] = 'media="' . htmlspecialchars($options['media'], ENT_QUOTES, 'UTF-8') . '"';
            }
            if (isset($options['onload'])) {
                $attributes[] = 'onload="' . htmlspecialchars($options['onload'], ENT_QUOTES, 'UTF-8') . '"';
            }
            $attrString = !empty($attributes) ? ' ' . implode(' ', $attributes) : '';
            $safeContent = str_replace('</style>', '<\/style>', $content);

            return "<style{$attrString}>" . $safeContent . "</style>\n";
        }

        if ($type === 'js') {
            $attributes = [];
            if (isset($options['defer']) && $options['defer']) {
                $attributes[] = 'defer';
            }
            if (isset($options['async']) && $options['async']) {
                $attributes[] = 'async';
            }
            $attrString = !empty($attributes) ? ' ' . implode(' ', $attributes) : '';
            $safeContent = str_replace('</script>', '<\/script>', $content);

            return "<script{$attrString}>" . $safeContent . "</script>\n";
        }

        return '';
    }
}
