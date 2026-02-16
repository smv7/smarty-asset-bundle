<?php
// src/CacheManager.php

namespace SmartyBundler;

use Wikimedia\Minify\CSSMin;
use Wikimedia\Minify\JavaScriptMinifier;

class CacheManager
{
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
        $filePath = $this->cacheDir . '/' . $cacheKey . '.' . $type;
        $tempFile = $filePath . '.tmp';
        if (file_put_contents($tempFile, $content) !== false) {
            return rename($tempFile, $filePath);
        }

        return false;
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
        $tempFile = $this->cacheDir . '/' . $cacheKey . '.' . $type . '.tmp_' . getmypid();

        if (file_put_contents($tempFile, $content) === false) {
            @unlink($tempFile);
            return false;
        }

        $finalFile = $this->cacheDir . '/' . $cacheKey . '.' . $type;

        if (file_exists($finalFile)) {
            @unlink($tempFile);
            return true;
        }

        $result = rename($tempFile, $finalFile);

        if (!$result) {
            @unlink($tempFile);
        }

        return $result;
    }


    /**
     *  fastcgi_finish_request для фоновой задачи
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

            $tempFile = $this->cacheDir . '/' . $cacheKey . '.' . $type . '.tmp_' . getmypid();

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

                    if (!empty($content)) {
                        $content = $this->minify($content, $type);
                        file_put_contents($tempFile, $content);
                    }
                } else {
                    $content = (string)$source;
                    if (!($options['bundle_disable'] ?? false)) {
                        $content = $this->minify($content, $type);
                    }

                    $comment = "/* Generated from string bundle on " . date('Y-m-d H:i:s') . " */\n";
                    file_put_contents($tempFile, $comment . $content);
                }

                $finalFile = $this->cacheDir . '/' . $cacheKey . '.' . $type;

                if (!file_exists($finalFile)) {
                    rename($tempFile, $finalFile);
                } else {
                    @unlink($tempFile);
                }
            } catch (\Exception $e) {
                error_log("AssetBundle async cache generation failed: " . $e->getMessage());
                @unlink($tempFile);
            } finally {
                $this->cleanupTempFiles($cacheKey, $type);
            }
        });
    }

    /**
     * Очистка временных файлов текущего процесса
     */
    private function cleanupTempFiles(string $cacheKey, string $type): void
    {
        $pattern = $this->cacheDir . '/' . $cacheKey . '.' . $type . '.tmp_*';
        $files = glob($pattern);

        if ($files) {
            $currentPid = getmypid();
            foreach ($files as $file) {
                if (preg_match('/\.tmp_(\d+)$/', $file, $matches)) {
                    $filePid = (int)$matches[1];
                    if ($filePid != $currentPid) {
                        @unlink($file);
                    }
                }
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
        $files = glob($this->cacheDir . '/*.{css,js,tmp,lock}', GLOB_BRACE);
        foreach ($files as $file) {
            @unlink($file);
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

        $tempFile = $this->cacheDir . '/' . $cacheKey . '.' . $type . '.tmp_' . getmypid();

        if (file_put_contents($tempFile, $content) === false) {
            @unlink($tempFile);
            return false;
        }

        $finalFile = $this->cacheDir . '/' . $cacheKey . '.' . $type;

        if (file_exists($finalFile)) {
            @unlink($tempFile);

            return true;
        }

        $result = rename($tempFile, $finalFile);
        if (!$result) {
            @unlink($tempFile);
        }

        return $result;
    }
}
