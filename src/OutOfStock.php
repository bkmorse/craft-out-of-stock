<?php
/**
 * Out of Stock plugin for Craft CMS 3.x
 *
 * Get notified when products are (almost) out of stock.
 *
 * @link      https://swishdigital.co
 * @copyright Copyright (c) 2020 Swish Digital
 */

namespace swishdigital\outofstock;

use Craft;
use craft\web\View;

use yii\base\Event;
use craft\base\Plugin;
use craft\web\UrlManager;
use craft\services\Plugins;
use craft\services\Elements;

use craft\events\PluginEvent;
use craft\commerce\elements\Order;
use craft\commerce\elements\Variant;
use craft\commerce\services\LineItems;
use craft\events\RegisterUrlRulesEvent;
use swishdigital\outofstock\models\Settings;
use craft\commerce\events\LineItemEvent;
use swishdigital\outofstock\events\LowStockEvent;
use swishdigital\outofstock\jobs\SendEmailNotification;
use swishdigital\outofstock\services\OutOfStockService;

/**
 * Craft plugins are very much like little applications in and of themselves. We’ve made
 * it as simple as we can, but the training wheels are off. A little prior knowledge is
 * going to be required to write a plugin.
 *
 * For the purposes of the plugin docs, we’re going to assume that you know PHP and SQL,
 * as well as some semi-advanced concepts like object-oriented programming and PHP namespaces.
 *
 * https://docs.craftcms.com/v3/extend/
 *
 * @author    Swish Digital
 * @package   OutOfStock
 * @since     3.0.0
 *
 * @property  OutOfStockServiceService $outOfStockService
 * @property  Settings $settings
 * @method    Settings getSettings()
 */
class OutOfStock extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * Static property that is an instance of this plugin class so that it can be accessed via
     * OutOfStock::$plugin
     *
     * @var OutOfStock
     */
    public static $plugin;

    // Public Properties
    // =========================================================================

    /**
     * To execute your plugin’s migrations, you’ll need to increase its schema version.
     *
     * @var string
     */
    public $schemaVersion = '3.0.0';

    /**
     * Set to `true` if the plugin should have a settings view in the control panel.
     *
     * @var bool
     */
    public $hasCpSettings = true;

    /**
     * Set to `true` if the plugin should have its own section (main nav item) in the control panel.
     *
     * @var bool
     */
    public $hasCpSection = false;

    // Public Methods
    // =========================================================================

    /**
     * Set our $plugin static property to this class so that it can be accessed via
     * OutOfStock::$plugin
     *
     * Called after the plugin class is instantiated; do any one-time initialization
     * here such as hooks and events.
     *
     * If you have a '/vendor/autoload.php' file, it will be loaded for you automatically;
     * you do not need to load it in your init() method.
     *
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;

        // Register our site routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['siteActionTrigger1'] = 'out-of-stock/default';
            }
        );

        // Register our CP routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['cpActionTrigger1'] = 'out-of-stock/default/do-something';
            }
        );

        // Do something after we're installed
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                    // We were just installed
                }
            }
        );

        /**
         * Logging in Craft involves using one of the following methods:
         *
         * Craft::trace(): record a message to trace how a piece of code runs. This is mainly for development use.
         * Craft::info(): record a message that conveys some useful information.
         * Craft::warning(): record a warning message that indicates something unexpected has happened.
         * Craft::error(): record a fatal error that should be investigated as soon as possible.
         *
         * Unless `devMode` is on, only Craft::warning() & Craft::error() will log to `craft/storage/logs/web.log`
         *
         * It's recommended that you pass in the magic constant `__METHOD__` as the second parameter, which sets
         * the category to the method (prefixed with the fully qualified class name) where the constant appears.
         *
         * To enable the Yii debug toolbar, go to your user account in the AdminCP and check the
         * [] Show the debug toolbar on the front end & [] Show the debug toolbar on the Control Panel
         *
         * http://www.yiiframework.com/doc-2.0/guide-runtime-logging.html
         */
        Craft::info(
            Craft::t(
                'out-of-stock',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );

        // Listen for manual save of a line item
        Event::on(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, function(Event $event) {
            if ($event->element instanceof Variant) {
                // Call service
                $originalVariant = Variant::findOne($event->element->id);
                OutOfStock::$plugin->outOfStockService->checkVariantStock($event->element, $originalVariant);
            }
        });

        // Check after an order has been paid the new stock
        Event::on(Order::class, Order::EVENT_AFTER_ORDER_PAID, function(Event $event) {
            OutOfStock::$plugin->outOfStockService->checkStockAfterOrder($event->sender);
        });

        Event::on(OutOfStockService::class, OutOfStockService::EVENT_VARIANT_LOW_ON_STOCK, function(LowStockEvent $event) {
            // Add a job to the queue that will send the mail
            if ($this->settings->sendEmail) {
                Craft::$app->queue->push(new SendEmailNotification([
                    'variantId' => $event->variant->id,
                    'email' => $this->settings->recipients
                ]));
            }
        });
    }

    // Protected Methods
    // =========================================================================

    /**
     * Creates and returns the model used to store the plugin’s settings.
     *
     * @return \craft\base\Model|null
     */
    protected function createSettingsModel()
    {
        return new Settings();
    }

    /**
     * Returns the rendered settings HTML, which will be inserted into the content
     * block on the settings page.
     *
     * @return string The rendered settings HTML
     */
    protected function settingsHtml(): string
    {
        return Craft::$app->view->renderTemplate(
            'out-of-stock/settings',
            [
                'settings' => $this->getSettings()
            ]
        );
    }
}
