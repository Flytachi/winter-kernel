<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Res;

use Flytachi\Winter\Kernel\Factory\Mapping;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Stereotype\Controller;

final class ResourceTree
{
    private static string $controllerClass;
    private static string $controllerClassMethod;
    private static ?string $template;
    private static string $resource;
    private static array $resourceData;
    private static array $resourceAdditional = [];

    public static function init(
        string $controllerClass,
        string $controllerClassMethod,
        ?string $template,
        string $resource
    ): void {
        self::$controllerClass = $controllerClass;
        self::$controllerClassMethod = $controllerClassMethod;
        self::$template = $template;
        self::$resource = $resource;
    }

    final public static function importResource(): void
    {
        include self::$resource;
    }

    final public static function registerAdditionResource(string $resource): void
    {
        self::$resourceAdditional[] = $resource;
    }

    final public static function getResourceData(?string $valueKey = null): mixed
    {
        return empty($valueKey)
            ? self::$resourceData
            : (self::$resourceData[$valueKey] ?? null);
    }

    public static function render(array $resourceData): void
    {
        self::$resourceData = $resourceData;
        if (self::$template == null) {
            include self::$resource;
        } else {
            include self::$template;
        }
        echo self::debugger();
    }

    private static function debugger(): ?string
    {
        if (env('DEBUG', false)) {
            ob_start();
            $delta = round(microtime(true) - WINTER_STARTUP_TIME, 3);
            ?>
            <link rel="stylesheet" type="text/css" href="/static/extra/css/debug.css"/>
            <script type="text/javascript" src="/static/extra/js/debug.js"></script>
            <button id="extra_debug-btn" onclick="ExtraDebugBar()"><em>Debug</em></button>

            <div id="extra_debug-bar">
                <div id="extra_debug-bar_body-indicator">
                    <b>Memory:</b> <?= bytes(memory_get_usage(), 'MiB')  ?> /
                    <b>Time:</b> <?= ($delta < 0.001) ? 0.001 : $delta; ?> sec
                </div>

                <div id="extra_debug-bar_body-accordion-container">

                    <input type="checkbox" id="debug-item_general">
                    <label for="debug-item_general">GENERAL</label>
                    <div class="extra_debug-accordion-body">
                        <pre><?php print_r([
                                'sapi' => PHP_SAPI,
                                'timezone' => date_default_timezone_get(),
                                'date' => date(DATE_ATOM),
                                'controllerClass' => self::$controllerClass,
                                'controllerClassMethod' => self::$controllerClassMethod,
                                'template' => self::$template
                                    ? str_replace(Kernel::$pathRoot, '', self::$template)
                                    : null,
                                'resource' => str_replace(Kernel::$pathRoot, '', self::$resource),
                                'resourceAdditional' => array_map(
                                    fn($resource) => str_replace(Kernel::$pathRoot, '', $resource),
                                    self::$resourceAdditional
                                ),
                                 'resourceData' => self::$resourceData
                            ]) ?></pre>
                    </div>

                    <input type="checkbox" id="debug-item_mapping">
                    <label for="debug-item_mapping">MAPPING</label>
                    <div class="extra_debug-accordion-body">
                        <?php
                        try {
                            $declaration = Mapping::scanningDeclaration();
                            foreach ($declaration->getChildren() as $item) {
                                if (
                                    is_subclass_of($item->getClassName(), Controller::class)
                                    && ($item->getMethod() == 'GET'
                                    || $item->getMethod() == '')
                                ) {
                                    $classMethod = $item->getClassName() . '->' . $item->getClassMethod();
                                    echo sprintf(
                                        "<div>"
                                        . "<a href=\"/%s\" style='font-size: 1rem; color: cyan; "
                                        . "text-decoration-color: cyan' target=\"_blank\">/%s</a> - "
                                        .  " <em>(%s)</em>"
                                        .  "</div>",
                                        $item->getUrl(),
                                        $item->getUrl(),
                                        $classMethod
                                    ) , "</br>";
                                }
                            }
                        } catch (\Throwable $e) {
                            echo $e->getMessage();
                        }
                        ?>
                    </div>

                    <hr>

                    <?php foreach ($GLOBALS as $name => $INFO) : ?>
                        <?php if (!empty($INFO)) : ?>
                            <?php $name = ltrim($name, '_'); ?>
                            <input type="checkbox" id="debug-item_<?= $name ?>">
                            <label for="debug-item_<?= $name ?>"><?= $name ?></label>
                            <div class="extra_debug-accordion-body">
                                <pre><?php print_r($INFO) ?></pre>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>

                </div>

            </div>
            <?php
            return ob_get_clean();
        } else {
            return null;
        }
    }
}
