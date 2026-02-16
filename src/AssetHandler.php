<?php
// src/AssetHandler.php
namespace SmartyBundler;

use Smarty\FunctionHandler\FunctionHandlerInterface;
use Smarty\Template;

class AssetHandler implements FunctionHandlerInterface
{
    public function __construct(private AssetBundle $combiner) {}

    /**
     * Обработка вызова {bundle} в шаблоне Smarty
     */
    public function handle($params, Template $template): string
    {
        $paramsArray = (array) $params;
        $hasInput = isset($paramsArray['input']) && $paramsArray['input'] !== '';
        $hasContent = isset($paramsArray['content']) && $paramsArray['content'] !== '';

        if (!$hasInput && !$hasContent) {
            return '<!-- SmartyBundle: missing input or content -->';
        }

        $files = [];
        $options = $paramsArray;

        if ($hasInput) {
            $input = $paramsArray['input'];
            $files = is_array($input) ? $input : [$input];
            unset($options['input']);

            foreach ($files as $index => $file) {
                if (!is_string($file) || trim($file) === '') {
                    unset($files[$index]);
                    trigger_error("SmartyBundle: empty file path in input", \E_USER_WARNING);
                }
            }

            if (empty($files)) {
                return '<!-- SmartyBundle: no valid files in input -->';
            }
        } else {
            $files = [];

            $content = $paramsArray['content'];
            if (!is_string($content) || trim($content) === '') {
                trigger_error('SmartyBundle: content is empty', \E_USER_WARNING);
                return '<!-- SmartyBundle: empty content -->';
            }

            if (strlen($content) > 500000) {
                trigger_error('SmartyBundle: content too large (max 500KB)', \E_USER_WARNING);
                return '<!-- SmartyBundle: content too large -->';
            }
        }

        return $this->combiner->processBundle($files, $options);
    }

    /**
     * Требуется интерфейсом FunctionHandlerInterface
     */
    public function isCacheable(): bool
    {
        return false;
    }

    public static function createCallable(AssetBundle $combiner): callable
    {
        $handler = new self($combiner);
        return function ($params, $template) use ($handler) {
            return $handler->handle($params, $template);
        };
    }
}
