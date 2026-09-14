<?php

namespace SmartyBundler;

use Wikimedia\Minify\CSSMin;
use Wikimedia\Minify\JavaScriptMinifier;

class CacheManager
{
    private const TMP_TTL = 300;

    private string $cacheDir;

    public function __construct(string $cacheDir)
    {
        $this->cacheDir = rtrim($cacheDir, '/');
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    public function generateCacheKey(array $files, array $options, string $type): string
    {
        $hashContext = hash_init('md5');

        foreach ($files as $file) {
            if (file_exists($file)) {
                $mtime = filemtime($file);
                $size = filesize($file);
                hash_update($hashContext, $file . ':' . $mtime . ':' . $size . '|');
            }
        }

        hash_update($hashContext, 'type:' . $type);

        $optionHashKeys = ['media', 'defer', 'async'];
        foreach ($optionHashKeys as $key) {
            if (isset($options[$key])) {
                hash_update($hashContext, $key . ':' . serialize($options[$key]) . '|');
            }
        }

        $totalHash = hash_final($hashContext);
        return 'b_' . substr($totalHash, 0, 16);
    }

    public function getCachedFilePath(string $cacheKey, string $type, array $options = []): ?string
    {
        if (($options['bundle_disable'] ?? false)) {
            return null;
        }

        $path = $this->cacheDir . '/' . $cacheKey . '.' . $type;
        return file_exists($path) ? $path : null;
    }

    public function saveCache(string $cacheKey, string $type, string $content): bool
    {
        $tempFile = $this->makeTempPath($cacheKey, $type);

        if (@file_put_contents($tempFile, $content) === false) {
            @unlink($tempFile);
            return false;
        }

        $finalFile = $this->cacheDir . '/' . $cacheKey . '.' . $type;

        if (!file_exists($tempFile)) {
            return false;
        }

        $result = @rename($tempFile, $finalFile);

        if (!$result) {
            @unlink($tempFile);
        }

        return (bool) $result;
    }

    public function generateAndSaveCache(array $files, string $cacheKey, string $type, array $options = []): bool
    {
        if (($options['bundle_disable'] ?? false) ||
            $this->getCachedFilePath($cacheKey, $type, $options) !== null
        ) {
            return true;
        }

        $content = '';
        foreach ($files as $file) {
            if (file_exists($file)) {
                $fileContent = file_get_contents($file);
                if ($fileContent !== false) {
                    if ($type === 'js') {
                        $fileContent = preg_replace('/^[\t ]*console\.(log|debug)\(.*?\);?[\t ]*$/m', '', $fileContent);
                    }
                    $content .= $fileContent . "\n";
                }
            }
        }

        if (empty($content)) {
            return false;
        }

        $content = $this->minify($content, $type);

        return $this->saveCacheAtomic($cacheKey, $type, $content);
    }

    /**
     * Атомарное сохранение кеша через временный файл
     */
    private function saveCacheAtomic(string $cacheKey, string $type, string $content): bool
    {
        $tempFile = $this->makeTempPath($cacheKey, $type);

        if (@file_put_contents($tempFile, $content) === false) {
            @unlink($tempFile);
            return false;
        }

        $finalFile = $this->cacheDir . '/' . $cacheKey . '.' . $type;

        if (file_exists($finalFile)) {
            @unlink($tempFile);
            return true;
        }

        if (!file_exists($tempFile)) {
            return false;
        }

        $result = @rename($tempFile, $finalFile);

        if (!$result) {
            @unlink($tempFile);
        }

        return (bool) $result;
    }

    /**
     * fastcgi_finish_request для фоновой задачи
     */
    public function scheduleCacheGeneration($source, string $cacheKey, string $type, array $options = []): void
    {
        if (($options['bundle_disable'] ?? false) ||
            $this->getCachedFilePath($cacheKey, $type, $options) !== null
        ) {
            return;
        }

        register_shutdown_function(function () use ($source, $cacheKey, $type, $options) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            ignore_user_abort(true);
            set_time_limit(30);

            if ($this->getCachedFilePath($cacheKey, $type, $options) !== null) {
                return;
            }

            $tempFile = $this->makeTempPath($cacheKey, $type);

            try {
                if (is_array($source)) {
                    $content = '';
                    foreach ($source as $file) {
                        if (file_exists($file)) {
                            $fileContent = file_get_contents($file);
                            if ($fileContent !== false) {
                                if ($type === 'js') {
                                    $fileContent = preg_replace('/^[\t ]*console\.(log|debug)\(.*?\);?[\t ]*$/m', '', $fileContent);
                                }
                                $content .= $fileContent . "\n";
                            }
                        }
                    }

                    if (empty($content)) {
                        return;
                    }

                    $content = $this->minify($content, $type);

                    if (@file_put_contents($tempFile, $content) === false) {
                        return;
                    }
                } else {
                    $content = (string) $source;
                    if (!($options['bundle_disable'] ?? false)) {
                        $content = $this->minify($content, $type);
                    }

                    $comment = "/* Generated from string bundle on " . date('Y-m-d H:i:s') . " */\n";

                    if (@file_put_contents($tempFile, $comment . $content) === false) {
                        return;
                    }
                }

                $finalFile = $this->cacheDir . '/' . $cacheKey . '.' . $type;

                if (file_exists($finalFile)) {
                    @unlink($tempFile);
                    return;
                }

                if (!file_exists($tempFile)) {
                    return;
                }

                @rename($tempFile, $finalFile);
            } catch (\Exception $e) {
                error_log("AssetBundle async cache generation failed: " . $e->getMessage());
                @unlink($tempFile);
            } finally {
                $this->cleanupTempFiles();
            }
        });
    }

    /**
     * Уникальный путь для временного файла
     */
    private function makeTempPath(string $cacheKey, string $type): string
    {
        return $this->cacheDir . '/' . $cacheKey . '.' . $type
            . '.tmp_' . getmypid() . '_' . uniqid('', true);
    }

    /**
     * Удаление устаревших временных файлов.
     * Живые tmp-файлы параллельных воркеров НЕ трогаем.
     */
    private function cleanupTempFiles(): void
    {
        $files = glob($this->cacheDir . '/*.tmp_*');

        if (!$files) {
            return;
        }

        $now = time();

        foreach ($files as $file) {
            if (is_file($file) && ($now - filemtime($file)) > self::TMP_TTL) {
                @unlink($file);
            }
        }
    }

    private function minify(string $content, string $type): string
    {
        if ($type === 'css') {
            return CSSMin::minify($content);
        }

        if ($type === 'js') {
            return JavaScriptMinifier::minify($content);
        }

        return $content;
    }

    public function clearCache(): void
    {
        $patterns = [
            $this->cacheDir . '/*.{css,js}',
            $this->cacheDir . '/*.tmp_*',
        ];

        foreach ($patterns as $pattern) {
            $files = glob($pattern, GLOB_BRACE);
            if (!$files) {
                continue;
            }
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * Генерирует ключ кеша для строкового контента
     */
    public function generateContentCacheKey(string $content, array $options, string $type): string
    {
        $salt = 'content_bundle_v1_';

        $hashData = [
            'content' => $content,
            'type' => $type,
            'options' => $options,
            'salt' => $salt,
            'version' => '1.0'
        ];

        $ignoredOptions = ['preload', 'cache_buster'];
        foreach ($ignoredOptions as $opt) {
            if (isset($hashData['options'][$opt])) {
                unset($hashData['options'][$opt]);
            }
        }

        ksort($hashData['options']);

        $hash = md5(serialize($hashData));
        return 'c_' . substr($hash, 0, 16);
    }

    /**
     * Атомарное создание кеша из строкового контента
     */
    public function saveContentCache(string $cacheKey, string $type, string $content, array $options = []): bool
    {
        if ($this->getCachedFilePath($cacheKey, $type, $options) !== null) {
            return true;
        }

        if (!($options['bundle_disable'] ?? false)) {
            $content = $this->minify($content, $type);
        }

        $comment = "/* Generated from string bundle on " . date('Y-m-d H:i:s') . " */\n";
        $content = $comment . $content;

        $tempFile = $this->makeTempPath($cacheKey, $type);

        if (@file_put_contents($tempFile, $content) === false) {
            @unlink($tempFile);
            return false;
        }

        $finalFile = $this->cacheDir . '/' . $cacheKey . '.' . $type;

        if (file_exists($finalFile)) {
            @unlink($tempFile);
            return true;
        }

        if (!file_exists($tempFile)) {
            return false;
        }

        $result = @rename($tempFile, $finalFile);
        if (!$result) {
            @unlink($tempFile);
        }

        return (bool) $result;
    }
}
