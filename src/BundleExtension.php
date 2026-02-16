<?php
// src/BundleExtension.php
namespace SmartyBundler;

use Smarty\Extension\Base;
use Smarty\Extension\ExtensionInterface;

class BundleExtension extends Base implements ExtensionInterface
{
    public function __construct(private AssetBundle $combiner) {}

    public function getAssetBundle(): AssetBundle
    {
        return $this->combiner;
    }

    public function getFunctionHandler(string $functionName): ?\Smarty\FunctionHandler\FunctionHandlerInterface
    {
        if ($functionName === 'bundle') {
            return new AssetHandler($this->combiner);
        }

        return null;
    }

    public function getBlockHandler(string $blockName): ?\Smarty\BlockHandler\BlockHandlerInterface
    {
        return null;
    }

    public function isCacheable(): bool
    {
        return false;
    }

    public function getTagCompiler(string $tag): ?\Smarty\Compile\CompilerInterface
    {
        return null;
    }

    public function getModifierCompiler(string $modifier): ?\Smarty\Compile\Modifier\ModifierCompilerInterface
    {
        return null;
    }

    public function getModifierCallback(string $modifierName): ?callable
    {
        return null;
    }

    public function getPreFilters(): array
    {
        return [];
    }

    public function getPostFilters(): array
    {
        return [];
    }

    public function getOutputFilters(): array
    {
        return [];
    }
}
