<?php
/**
 * Adds a new shipping method to WooCommerce
 */
interface StuartCustomShippingMethod
{
    public function __construct($instance_id = 0);
    // Logs management
    public function purgeLogs();
    public function addLog($type = '', $content);
    // Plugin settings
    public function getFields();
    public function admin_options();
    public function init_form_fields();
    public function is_available($package);
    // Auth
    public function setStuartAuth($refresh = false, $return_object = false);
    public function getSession($order_id = false);
    // Create jobs
    public function getTimeZone();
    public function getDeliveryTimeList($context = false);
    public function prepareJobObject($object);
    public function checkAddressInZone($address_str, $type = "picking");
    public function calculate_shipping($package = array());
    public function getOption($name, $context = false);
    public function updateOption($name, $value);
    public function doPickupTest();
    public function isDeliveryTime($time = 'now', $context = false);
    public function createJob($order_id);
    // Get jobs info
    public function getJobId($order_id);
    public function getJob($id_job);
    public function getJobPricing($object = false);
    public function getFirstDeliveryTime($time_list = array());
    public function getPickupTime($object = false);
    public function dateToFormat($str = 'now', $format = 'c');
    public function formatToDate($format = 'c', $str = 'now');
    public function getTime($str = 'now');
    public function getSettingTime($date = null, $time = null);
    public function checkJobStates();
    public function getDelay($context = false);
    // Update jobs
    public function setPickupTime($pickup_time, $order_id = false);
    public function rescheduleJob($pickup_time = 'now', $order_id = false, $creation = false);
    // Cancel job
    public function cancelJob($order_id);
    // Misc utils
    public function connectedNotice();
    public function notConnectedNotice($obj = false);
}
