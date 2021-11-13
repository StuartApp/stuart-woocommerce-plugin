<?php
/*
    Plugin Name: Stuart Delivery For WooCommerce
    Plugin URI: http://plugins.stuart-apps.solutions/wordpress/
    Description: Integrate Stuart Delivery into your WooCommerce site
    Author: Jose Hervas Diaz <ji.hervas@stuart.com>
    Version: 1.0.0
    License : GPL
    Text Domain: stuart-delivery
    Domain Path: /languages/
*/

// Prevent direct access to this php file through URL
if (! defined('WP_CONTENT_DIR')) {
    exit;
}

// Autoload all the dependencies of this plugin
require_once(WP_CONTENT_DIR . '/plugins/stuart-woocommerce/vendor/autoload.php');
require_once(WP_CONTENT_DIR . '/plugins/stuart-woocommerce/interfaces/plugin-controller.php');

class Stuart implements MainPluginController
{
    public $version = '1.0.0';
    public $settings;
    public $file = __FILE__;
    private static $instance;
    private static $delivery; // StuartShippingMethod, see: custom-shipping-method.php
    public $review_order = false;

    public function update()
    {
        $installedVersion = get_option('stuart_plugin_version', null);
        if ($this->version === $installedVersion) {
            return;
        }
        if ($installedVersion === null) {
            // 1st time installation
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            // Setup DB
            $charset_collate = '';
            if (! empty($wpdb->charset)) {
                $charset_collate .= "DEFAULT CHARACTER SET $wpdb->charset";
            }
            if (! empty($wpdb->collate)) {
                $charset_collate .= " COLLATE $wpdb->collate";
            }
            $result_db_table_create_query = "CREATE TABLE IF NOT EXISTS ". $wpdb->prefix . "stuart_logs" . " (
        log_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        type varchar(255) NOT NULL DEFAULT '',
        content text NOT NULL DEFAULT '',
        date_created datetime DEFAULT NULL,
        PRIMARY KEY (log_id)
        ) ". $charset_collate .";";
            dbDelta($result_db_table_create_query);
            // Initialize all the plugin properties
            $delivery = $this->initializeCustomDeliveryClass();
            $fields = $delivery->getFields();
            foreach ($fields as $field_name => $field_values) {
                if (isset($field_values['default']) && !empty($field_values['default']) && !isset($new_settings[$field_name])) {
                    $new_settings[$field_name] = $field_values['default'];
                }
            }
            update_option('stuart_plugin_settings', $new_settings);
            $settings = $new_settings;
            // Update plugin version
            update_option('stuart_plugin_version', $this->version);
        }
    }

    public function __construct()
    {
        require_once(ABSPATH . '/wp-admin/includes/plugin.php');
        if (! is_plugin_active('woocommerce/woocommerce.php') && ! function_exists('WC')) {
            return;
        } else {
            if (version_compare(PHP_VERSION, '5.3', 'lt')) {
                return add_action('admin_notices', array( $this, 'phpVersionNotice' ));
            }
            $this->hooks();
            load_plugin_textdomain('stuart-delivery', false, basename(dirname(__FILE__)) . '/languages');
            $this->addCustomEndpointsToThisSite();
        }
    }

    public function phpVersionNotice()
    {
        ?><div class='updated'>
            <p><?php echo sprintf(esc_html__('Stuart Delivery Plugin requires PHP 5.3 or higher and your current PHP version is %s. Please (contact your host to) update your PHP version.', 'stuart-delivery'), PHP_VERSION); ?></p>
        </div><?php
    }

    public static function LoadCustomShippingMethod()
    {
        require_once 'custom-shipping-method.php';
    }

    public static function initializeCustomDeliveryClass()
    {
        if (is_null(self::$delivery)) {
            self::LoadCustomShippingMethod();
            self::$delivery = new StuartShippingMethod();
        }
        return self::$delivery;
    }

    public function addStuartDeliveryShippingMethod($methods)
    {
        $delivery = $this->initializeCustomDeliveryClass();
        $methods[$delivery->id] = 'StuartShippingMethod';
        return $methods;
    }

    public function hooks()
    {
        // Plugin settings
        add_action('woocommerce_loaded', array($this, 'update'));
        add_action('woocommerce_shipping_init', array($this, 'LoadCustomShippingMethod'), 10, 0);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array( $this, 'getPluginActions' ));
        add_filter('woocommerce_shipping_methods', array($this, 'addStuartDeliveryShippingMethod'));
        // Create order on pay accepted
        add_action('woocommerce_checkout_create_order', array($this, 'newOrder'));
        add_action('woocommerce_new_order', array($this, 'newOrder'));
        add_action('woocommerce_process_shop_order_meta', array($this, 'newOrder'));
        // Add and save checkout custom field
        add_filter('woocommerce_after_order_notes', array($this, 'setHiddenFormField'), 999, 4);
        add_action('woocommerce_checkout_update_order_meta', array($this, 'saveCheckoutField'));
        // On order pending
        add_action('woocommerce_order_status_pending', array($this, 'orderStatusPending'));
        // On order processing
        add_action('woocommerce_order_status_processing', array($this, 'orderStatusProcessing'));
        // On order cancel
        add_action('woocommerce_order_status_cancelled', array($this, 'orderStatusCancelled'));
        // Try to cancel on refund
        add_action('woocommerce_order_status_refunded', array($this, 'orderStatusCancelled'));
        // Backoffice Order Management
        add_action('woocommerce_admin_order_data_after_shipping_address', array($this, 'orderMetaShipping'));
        add_action('save_post', array($this, 'saveShippingDetails'));
     
        // Hook After shipping informations
        $settings = get_option('woocommerce_stuart_delivery_shipping_method_settings');

        $hook_key = wp_is_mobile() ? 'hook_frontend_mobile' : 'hook_frontend_desktop';

        if (!empty($settings) && isset($settings[$hook_key])) {
            $hook_frontend = $settings[$hook_key];
        } else {
            $hook_frontend = 'woocommerce_review_order_after_shipping';
        }

        add_action($hook_frontend, array($this, 'reviewOrderAfterShipping'), 10, 0);

        // Add order confirmation tracking URL
        add_action('woocommerce_thankyou', array($this, 'addContentOrderConfirmation'));
    }

    public function newOrder($order_id)
    {
        $delivery = $this->initializeCustomDeliveryClass();
        if ($delivery->getOption('enabled', new WC_Order($order_id)) == 'yes') {
            $order = new WC_Order($order_id);
            $pickup_time = $delivery->getPickupTime($order);
            $delivery->setPickupTime($pickup_time, $order_id);
            $session = $delivery->getSession();
            if (!empty($session)) {
                $pickup_id = $session->get('stuart_pickup_id');
                if (!empty($order_id) && !empty($pickup_id)) {
                    update_post_meta($order_id, 'stuart_pickup_id', $pickup_id);
                }
            }
        }
    }

    public function orderPaid($order_id)
    {
        $delivery = $this->initializeCustomDeliveryClass();
        $order = new WC_Order($order_id);

        if ($order->has_shipping_method($delivery->id)) {
            $test = $delivery->getOption('create_delivery', $order) == 'created' && $delivery->getOption('enabled', $order) == 'yes';

            $delivery->addLog('Order Paid', array('order' => $order_id, 'test' => $test));
            
            if ($test) {
                $delivery->createJob($order_id);
                do_action('stuart_order_paid', $order);
            }
        }
    }

    public function orderStatusProcessing($order_id)
    {
        $delivery = $this->initializeCustomDeliveryClass();
        $order = new WC_Order($order_id);
        if ($order->has_shipping_method($delivery->id)) {
            $test = $delivery->getOption('create_delivery', $order) == 'created' && $delivery->getOption('enabled', $order) == 'yes' && $delivery->getOption('start_delivery_on_order_processing', $order) == 'yes';
            $delivery->addLog('Order Processing', array('order' => $order_id, 'test' => $test));
            if ($test) {
                $this->orderPaid($order_id);
            }
        }
    }

    public function orderStatusCancelled($order_id)
    {
        $delivery = $this->initializeCustomDeliveryClass();

        $order = new WC_Order($order_id);

        if ($order->has_shipping_method($delivery->id)) {
            $test = $delivery->getOption('cancel_delivery_on_order_cancel', $order) == 'yes' && $delivery->getOption('enabled', $order) == 'yes';

            $delivery->addLog('Order Cancelled', array('order' => $order_id, 'test' => $test));
           
            if ($test) {
                $delivery->cancelJob($order_id);
            }
        }
    }

    public function orderStatusPending($order_id)
    {
        $delivery = $this->initializeCustomDeliveryClass();
        $order = new WC_Order($order_id);
        if ($order->has_shipping_method($delivery->id)) {
            $test = $delivery->getOption('create_delivery', $order) == 'created' && $delivery->getOption('enabled', $order) == 'yes' && $delivery->getOption('start_delivery_on_order_pending', $order) == 'yes';
            $delivery->addLog('Order Pending', array('order' => $order_id, 'test' => $test));
            if ($test) {
                $this->orderPaid($order_id);
            }
        }
    }

    public function saveShippingDetails($order_id)
    {
        $post_type = get_post_type($order_id);
        
        if ("shop_order" != $post_type) {
            return;
        }

        $delivery = $this->initializeCustomDeliveryClass();
        
        if (isset($_POST['pickup_date'])) {
            $pickup_date = wc_clean($_POST[ 'pickup_date' ]);

            if (!empty($pickup_date)) {
                if (isset($_POST['pickup_id'])) {
                    $pickup_id = wc_clean($_POST[ 'pickup_id' ]);
                    update_post_meta($order_id, 'stuart_pickup_id', $pickup_id);
                }

                $pickup_date = str_replace(array('/', 'h'), array('-', ':'), $pickup_date);

                $explode_time = explode(' ', $pickup_date);

                $pickup_day = $explode_time[0];
                $pickup_hour = isset($explode_time[1]) ? $explode_time[1] : date('H:i');

                $pickup_time = $delivery->getSettingTime($pickup_day, $pickup_hour);

                update_post_meta($order_id, 'stuart_modified_date', 1);

                if (isset($_POST['action_job']) && wc_clean($_POST['action_job']) == 'reschedule') {
                    $delivery->rescheduleJob($pickup_time, $order_id, true);
                } else {
                    $delivery->setPickupTime($pickup_time, $order_id);
                }
            }
        }

        if (isset($_POST['action_job']) && wc_clean($_POST['action_job']) == 'cancel') {
            $delivery->cancelJob($order_id);
        }
    }

    public function orderMetaShipping($order)
    {
        $delivery = $this->initializeCustomDeliveryClass();
        $delivery->checkJobStates();
        $job_id = $delivery->getJobId($order->get_id());

        $pickup_time = $delivery->getPickupTime($order);

        $job = $delivery->getJob($job_id);

        $delivery_status = array(
            "not_schedule"=> esc_html__('This delivery hasn\'t been sent to Stuart yet.', 'stuart-delivery'),
            "new"         => esc_html__('Delivery accepted by Stuart and will be assigned to a driver.', 'stuart-delivery'),
            "scheduled"   => esc_html__('Delivery has been scheduled. It will start later.', 'stuart-delivery'),
            "searching"   => esc_html__('Stuart is looking for a driver. It should start soon.', 'stuart-delivery'),
            "accepted"    => esc_html__('Stuart found a driver and delivery will begin on time.', 'stuart-delivery'),
            "in_progress" => esc_html__('Driver has accepted the job and started the delivery.', 'stuart-delivery'),
            "finished"    => esc_html__('The package was delivered successfully.', 'stuart-delivery'),
            "canceled"    => esc_html__('The package won\'t be delivered as it was cancelled by the client.', 'stuart-delivery'),
            "voided"      => esc_html__('Delivery cancelled, you won\'t be charged.', 'stuart-delivery'),
            "expired"     => esc_html__('Delivery has expired. No driver accepted the job. It didn\'t cost any money.', 'stuart-delivery')
        );

        if ((int) $job_id !== 0 && isset($job->status) && isset($delivery_status[$job->status])) {
            $current_state_title = $job->status;
            $current_state_description = $delivery_status[$job->status];
        } else {
            $current_state_description = $delivery_status['not_schedule'];
            $current_state_title = 'not_schedule';
        }

        $errors = get_post_meta($order->get_id(), 'stuart_job_creation_error', true);

        ob_start(); ?>
        <style>
            
            .stuart-backoffice-wrapper {
                display: block;
                padding: 10px 15px;
                border-radius: 5px;
                box-shadow: 0px 0px 8px -5px #333;
            }
            .stuart-backoffice-wrapper h3 { margin-top: 10px; }
            
            .stuart-logo-wrapper {
                display: block;
                height: 40px;
            }
            
            .stuart-logo-wrapper img { 
                max-width: 100%; 
                display: block; 
                max-height: 100%;
            }

        </style>
        <div class="stuart-backoffice-wrapper">
                
            <div class="address">
                <div class="stuart-logo-wrapper"><img src="<?php echo plugin_dir_url(__FILE__).'img/logo_stuart.png'; ?>" alt="Stuart Logo"></div>
                <h3><?php esc_html_e('Delivery state :', 'stuart-delivery');
        echo ' '.esc_html(ucfirst(str_replace('_', ' ', $current_state_title))); ?></h3>
                <p<?php if (empty($pickup_time)) {
            echo ' class="none_set"';
        } ?>>
                    <b><?php esc_html_e('Pickup date :', 'stuart-delivery'); ?></b>
                    <?php echo (!empty($pickup_time)) ? esc_html($delivery->formatToDate('d/m/Y H:i', is_numeric($pickup_time) ? $pickup_time : $delivery->getTime($pickup_time))) : esc_html__('No pickup time set.', 'stuart-delivery'); ?>
                </p>
                <p><i>
                    <?php echo esc_html($current_state_description); ?>
                </i></p>

                <?php if ($errors && is_array($errors)) : ?>
                    <div class="woocommerce-alert woocommerce-error">
                        <ul>
                            <?php foreach ($errors as $error_key => $error_value) {
            if (!is_array($error_value)) {
                echo '<li><b>'.$error_key. '</b> : '.$error_value.'</li>';
            }
        } ?>
                        </ul>
                    </div>

                <?php endif; ?>

            </div>
            <?php if (in_array($current_state_title, array('not_schedule', 'expired', 'canceled', 'voided', 'new', 'scheduled', 'searching'))) : ?>
                <div class="stuart_edit_pickup_time"><?php

                    $pickup_id = $order->get_meta('stuart_pickup_id', true);

        woocommerce_wp_text_input(array(
                        'id' => 'pickup_date',
                        'label' => esc_html__('Pickup date & time ', 'stuart-delivery'),
                        'style' => 'width:150px; display: block;',
                        'placeholder' => '23/12/2018 13:59',
                        'value' => !empty($pickup_time) ? esc_html($delivery->formatToDate('d/m/Y H:i', is_numeric($pickup_time) ? $pickup_time : $delivery->getTime($pickup_time))) : '',
                        'description' => esc_html__('Format : dd/mm/yyyy hh:mm', 'stuart-delivery'),
                    )); ?></div>
                <div class="stuart_edit_job_action"><?php
                    woocommerce_wp_select(array(
                        'id' => 'action_job',
                        'label' => esc_html__('Cancel or change delivery', 'stuart-delivery'),
                        'wrapper_class' => 'form-field-wide misha-set-tip-style',
                        'value' => 'no',
                        'description' => esc_html__('Do you want to cancel or relaunch the delivery now ?', 'stuart-delivery'),
                        'options' => array(
                            'no' => esc_html__('No', 'stuart-delivery'),
                            'reschedule' => esc_html__('Schedule with time above', 'stuart-delivery'),
                            'cancel' => esc_html__('Cancel it now', 'stuart-delivery'),
                        )
                    )); ?></div>
            <?php
                else :
                    if (isset($job->deliveries[0]->tracking_url)) :
                        ?>
                        <p><a href='<?php echo esc_url($job->deliveries[0]->tracking_url); ?>' target='_blank'><?php esc_html_e('Click here to follow delivery.', 'stuart-delivery'); ?></a></p>
                        <?php
                    endif;
        endif; ?>
            <div class="clear"></div>
        </div>
        <?php
        
        $content = ob_get_clean();

        echo apply_filters('stuart_backoffice_html', $content, $delivery, $order);
    }

    public function setHiddenFormField($checkout)
    {
        $delivery = $this->initializeCustomDeliveryClass();
        $pickup_time = $delivery->getPickupTime();
        echo '<div id="stuart_hidden_checkout_field">
                <input type="hidden" class="input-hidden" name="stuart_pickup_time" id="stuart_pickup_time" value="' . esc_html($pickup_time) . '">
        </div>';
    }

    public function saveCheckoutField($order_id)
    {
        if (isset($_POST['stuart_pickup_time']) && ! empty($_POST['stuart_pickup_time'])) {
            $order = new WC_Order($order_id);
            $delivery = $this->initializeCustomDeliveryClass();
            $time = sanitize_text_field($_POST['stuart_pickup_time']);
            if ($order->has_shipping_method($delivery->id) && $time) {
                update_post_meta($order_id, 'stuart_pickup_time', $time);
            }
            do_action('stuart_save_checkout_time', $order, $time);
        }
    }

    public function reviewOrderAfterShipping()
    {
        if (function_exists('is_checkout') && !is_checkout()) {
            return;
        }

        if ($this->review_order == true) {
            return;
        } else {
            $this->review_order = true;
        }

        // WooFunnel workaround
        if (isset($_GET['wfacp_is_checkout_override']) && isset($_GET['wc-ajax']) && $_GET['wc-ajax'] == "update_order_review" && $_GET['wfacp_is_checkout_override'] == "yes") {
            return;
        }

        $delivery = $this->initializeCustomDeliveryClass();

        $cart = $this->getCart();
        $packages = $cart->get_shipping_packages();

        if ($delivery->getOption('enabled', $packages) == 'no') {
            return;
        }

        if (!isset($packages[0]['destination']['address'])) {
            return;
        }

        $total_price = false;
        $time_list = array();

        foreach ($packages as $package) {
            $price = $delivery->getJobPricing($package);

            if ($price != false) {
                $total_price = ($total_price == false ? 0.00 : (float) $total_price) + (float) $price;
            }

            $tmp_time_list = $delivery->getDeliveryTimeList($package);

            if (empty($time_list)) {
                $time_list = $tmp_time_list;
            } else {

                // Let's go auto reduction
                foreach ($time_list as $day => $details) {
                    if (!$tmp_time_list[$day]) {
                        unset($time_list[$day]);
                    } else {
                        $day_time = $details['day'];
                        $time_before_service = (int) $delivery->dateToFormat($delivery->getSettingTime($day_time, $details['after']), 'Hi');
                        $time_after_service = (int) $delivery->dateToFormat($delivery->getSettingTime($day_time, $details['before']), 'Hi');
                        $pause_start = (int) $delivery->dateToFormat($delivery->getSettingTime($day_time, $details['pause_start']), 'Hi');
                        $pause_end = (int) $delivery->dateToFormat($delivery->getSettingTime($day_time, $details['pause_end']), 'Hi');

                        $new_details = $tmp_time_list[$day];
                        $new_time_before_service = (int) $delivery->dateToFormat($delivery->getSettingTime($day_time, $new_details['after']), 'Hi');
                        $new_time_after_service = (int) $delivery->dateToFormat($delivery->getSettingTime($day_time, $new_details['before']), 'Hi');
                        $new_pause_start = (int) $delivery->dateToFormat($delivery->getSettingTime($day_time, $new_details['pause_start']), 'Hi');
                        $new_pause_end = (int) $delivery->dateToFormat($delivery->getSettingTime($day_time, $new_details['pause_end']), 'Hi');

                        // Start of service this day is after the previous one
                        if ($time_before_service < $new_time_before_service) {
                            $time_list[$day]['after'] = $new_details['after'];
                        }

                        // Stop of service this day is after the previous one
                        if ($time_after_service > $new_time_after_service) {
                            $time_list[$day]['before'] = $new_details['before'];
                        }

                        if ($pause_start > $new_pause_start) {
                            $time_list[$day]['pause_start'] = $new_details['pause_start'];
                        }

                        if ($pause_end < $new_pause_end) {
                            $time_list[$day]['pause_end'] = $new_details['pause_end'];
                        }
                    }
                }
            }
        }

        if (empty($time_list) || $total_price === false) {
            $this->reviewNoticeFrontOrder();

            return;
        }

        $stuart_logo = plugin_dir_url(__FILE__).'img/logo_stuart.png';

        $current_job_time = (int) $delivery->getPickupTime($cart);

        $server_date = new DateTime("now", $delivery->getTimeZone());
        $server_time = $server_date->format('Y-m-d H:i:s');

        $first_time_available = $delivery->getFirstDeliveryTime($time_list);
        $start_pause = $first_time_available['pause_start'];
        $end_pause = $first_time_available['pause_end'];

        if (file_exists(get_template_directory().'/plugins/stuart/templates/after_shipping.php')) {
            include get_template_directory().'/plugins/stuart/templates/after_shipping.php';
        } else {
            include 'templates/after_shipping.php';
        }
    }

    public function getCart()
    {
        $cart = WC()->cart;
        if (!$cart) {
            WC()->frontend_includes();
            $cart = WC()->cart;
        }
        return $cart;
    }

    public function getCustomer()
    {
        $cart = $this->getCart();
        if ($cart) {
            return $cart->get_customer();
        } else {
            return new WC_Customer(get_current_user_id());
        }
    }

    public function followPickup($data, $render = true)
    {
        $delivery = $this->initializeCustomDeliveryClass();
        $order = new WC_Order((int) $data['id']);
        $html = '';

        if (!empty($data['id'])) {
            $job_id = $delivery->getJobId((int) $data['id']);
            $job = $delivery->getJob($job_id);

            if (empty($job)) {
                if ($order->has_status('completed') || ($order->has_status('processing') && $delivery->getOption('start_delivery_on_order_processing', $order) == 'yes')) {
                    $delivery->createJob((int) $data['id']);
                    $job_id = $delivery->getJobId((int) $data['id']);
                    $job = $delivery->getJob($job_id);
                }
            }

            $html = '';

            if (empty($job)) {
                $html .= "<div><p>".esc_html__("Your delivery hasn't been sent to Stuart yet, your payment might be pending approval, this page will keep updating until it begin.", "stuart-delivery")."</p></div><script type='text/javascript'>setTimeout(function(){window.location.reload();}, 60000);</script>";
            }

            if (isset($job->deliveries) && isset($job->deliveries[0]->tracking_url)) {
                $html .= "<script type='text/javascript'>window.location.href = '".esc_url($job->deliveries[0]->tracking_url)."';</script>";
            }

            $html = apply_filters('stuart_follow_pickup_html', $html, $order, $job);
        }

        if ($render == true) {
            header('Content-Type:text/html');
            echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"></head><body>';
            echo $html;
            echo "</body></html>";
            die;
        } else {
            return $html;
        }
    }

    public function getPluginActions($actions)
    {
        $custom_actions = array();
        $settings_link = 'admin.php?page=wc-settings&tab=shipping&section=stuartshippingmethod';
        $support_link = "https://community.stuart.engineering/";
        $custom_actions['stuart_settings'] = sprintf('<a href="%s">%s</a>', $settings_link, esc_html__('Settings', 'stuart-delivery'));
        $custom_actions['stuart_docs'] = sprintf('<a href="%s" target="_blank">%s</a>', $support_link, esc_html__('Docs & Support', 'stuart-delivery'));
        return array_merge($custom_actions, $actions);
    }

    public function getCourierMapLink()
    {
        $cart = $this->getCart();
        $delivery = $this->initializeCustomDeliveryClass();

        if ($cart) {
            $packages = $cart->get_shipping_packages();

            if (!empty($packages)) {
                foreach ($packages as $package) {
                    $params = $delivery->prepareJobObject($package);
                    
                    if (!empty($params)) {
                        $destination_addr = $params->job->dropoffs[0]->address;
                        $origin_addr = $params->job->pickups[0]->address;

                        $key = $delivery->getOption('maps_api_key_iframe');

                        if (!empty($key) && !empty($destination_addr) && !empty($origin_addr)) {
                            $delivery_mode = $delivery->getOption('delivery_mode', $package);

                            if (in_array($delivery_mode, ['bike', 'cargobike', 'cargobikexl'])) {
                                $mode = '&mode=bicycling';
                            } else {
                                $mode = '';
                            }
                            
                            return 'https://www.google.com/maps/embed/v1/directions?origin='.$origin_addr.'&destination='.$destination_addr.$mode.'&key='.$key;
                        }
                    }
                }
            }
        }

        return "";
    }

    public function addCustomEndpointsToThisSite()
    {
        add_action('rest_api_init', function () {
            register_rest_route('stuart-delivery/v1', '/update', array(
                'methods' => array('GET', 'POST'),
                'callback' => array($this, 'updatePickupTime'),
                'permission_callback' => '__return_true',
            ));

            register_rest_route('stuart-delivery/v1', '/address', array(
                'methods' => array('GET', 'POST'),
                'callback' => array($this, 'checkAddressInZoneRequest'),
                'permission_callback' => '__return_true',
            ));

            register_rest_route('stuart-delivery/v1', '/follow/(?P<id>\d+)', array(
                'methods' => array('GET'),
                'callback' => array($this, 'followPickup'),
                'permission_callback' => '__return_true',
                'args' => array(
                    'id' => array(
                        'validate_callback' => function ($param, $request, $key) {
                            return is_numeric($param);
                        }
                    ),
                ),
            ));
        });
    }

    public function getFollowUrl($order_id)
    {
        return get_rest_url(null, 'stuart-delivery/v1/follow/'.(int) $order_id);
    }

    public function addContentOrderConfirmation($order_id)
    {
        $delivery = $this->initializeCustomDeliveryClass();

        $order = new WC_Order($order_id);
        
        if (! $order->has_shipping_method($delivery->id)) {
            return;
        }

        $followurl = $this->getFollowUrl($order_id);
        $pickup_time = $delivery->getPickupTime($order);
        $fifteen_minutes = 900;

        if (function_exists('wp_date')) {
            $the_date = wp_date(get_option('date_format'). ' H:i', $pickup_time);
            $the_pickup = wp_date('H:i', $pickup_time + $fifteen_minutes);
        } else {
            $the_date = $delivery->dateToFormat($pickup_time, get_option('date_format'). ' H:i');
            $the_pickup = $delivery->dateToFormat($pickup_time + $fifteen_minutes, 'H:i');
        }

        echo "
        <div class='stuart_order_confirmation_follow'>
            <h3>".esc_html__('Follow your order', 'stuart-delivery')."</h3>
            <p><i>".esc_html__('Delivery around', 'stuart-delivery')." ".esc_html($the_date)." - ".esc_html($the_pickup)."</i> <a target='_blank' href='".esc_url($followurl)."'>(".esc_html__('Link', 'stuart-delivery').")</a></p>
            ".(
            $delivery->getOption('create_delivery', $order) !== "created" ? '' :
            "<iframe class='stuart-order-follow-iframe' border='0' style='width: 100%; display: block; margin: 20px auto; min-height: 350px; border: 1px solid #F2F2F2;' src='".esc_url($followurl)."'></iframe>"
        )."
        </div>
        ";
    }

    // Singleton
    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self;
        }
        return self::$instance;
    }
}

// Instantiate our class
$Stuart = Stuart::getInstance();
