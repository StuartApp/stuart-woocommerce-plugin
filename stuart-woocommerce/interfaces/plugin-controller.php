<?php
/**
 * Main controller of this plugin
 * - instantiates the "StuartShippingMethod" class
 * - adds custom endpoints to this WP site
 * - adds hooks to WordPress and WooCommerce lifecycle
 */
interface MainPluginController
{
    // Plugin manager
    public function update();
    public function __construct();
    public function phpVersionNotice();
    public static function getInstance();
    // Load the Custom Shipping method into WC
    public static function LoadCustomShippingMethod();
    public static function initializeCustomDeliveryClass();
    public function addStuartDeliveryShippingMethod($methods);
    // WooCommerce orders lifecycle
    public function hooks();
    public function reviewOrderAfterShipping();
    public function getCart();
    public function getCustomer();
    public function newOrder($order_id);
    public function orderStatusPending($order_id);
    public function orderStatusProcessing($order_id);
    public function updateDeliveryFromTheBO($order_id);
    public function followPickup($data, $render = true);
    public function getPluginActions($actions);
    public function orderStatusCancelled($order_id);
    // Add pickUpTime input to the order checkout page
    public function setHiddenFormField($checkout);
    public function saveCheckoutField($order_id);
    // Backoffice management
    public function orderMetaShipping($order);
    // Custom endpoints
    public function addCustomEndpointsToThisSite();
    // Misc. Utils
    public function getFollowUrl($order_id);
    public function addContentOrderConfirmation($order_id);
}
