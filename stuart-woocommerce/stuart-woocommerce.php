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

// Security: Prevent direct access to this php file through URL
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
  exit;
}

// Autoload all the dependencies of this plugin
require_once( WP_CONTENT_DIR . '/plugins/stuart-woocommerce/vendor/autoload.php' );

function my_log_file( $msg, $name = '' )
{
    // Print the name of the calling function if $name is left empty
    $trace=debug_backtrace();
    $name = ( '' == $name ) ? $trace[1]['function'] : $name;

    $error_dir = '/var/www/html/wordpress/logs/test.log';
    $msg = print_r( $msg, true );
    $log = $name . "  |  " . $msg . "\n";
    error_log( $log, 3, $error_dir );
}

// Main class, loads other files and adds hooks to WordPress
class Stuart {

    public $version = '1.0.0';
    public $settings;
    public $file = __FILE__;
    private static $instance;
    private static $stuart_api_client;
    public $review_order = false; 

    public function __construct() {
        // Check if WooCommerce is active
        require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
        if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) && ! function_exists( 'WC' ) ) {
            return;
        } else {
            // Check we have PHP 5.3 or higher
            if ( version_compare( PHP_VERSION, '5.3', 'lt' ) ) {
                return add_action( 'admin_notices', array( $this, 'phpVersionNotice' ) );
            }
            // Add filters/actions to WordPress
            $this->hooks();
            // i18n: load textdomain based on wordpress language
            load_plugin_textdomain( 'stuart-delivery', false, basename( dirname( __FILE__ ) ) . '/languages' );
        }
    }

    public function phpVersionNotice() {
        ?><div class='updated'>
            <p><?php echo sprintf( esc_html__( 'Stuart Delivery Plugin requires PHP 5.3 or higher and your current PHP version is %s. Please (contact your host to) update your PHP version.', 'stuart-delivery' ), PHP_VERSION ); ?></p>
        </div><?php
    }

    // Official Stuart PHP client package
    private function getStuartAPIClient() {
        $environment = \Stuart\Infrastructure\Environment::SANDBOX;
        if (get_option( 'stuart_plugin_environment', 0 ) === 'production'){
            $environment = \Stuart\Infrastructure\Environment::PRODUCTION;
        }
        $api_client_id = get_option( 'stuart_plugin_api_client_id', 0 );
        $api_client_secret = get_option( 'stuart_plugin_api_client_secret', 0 );
        $diskCache = new \Stuart\Cache\DiskCache("stuart_cache.txt");
        $authenticator = new \Stuart\Infrastructure\Authenticator($environment, $api_client_id, $api_client_secret, $diskCache);
        $httpClient = new \Stuart\Infrastructure\HttpClient($authenticator);
        $client = new \Stuart\Client($httpClient);
        my_log_file("Stuart PHP client ready");
        return $client;
	}

    public function hooks() {
        // Load settings
        add_action( 'woocommerce_loaded', array($this, 'update') );
        my_log_file("Hooks ready");
    }

    // Called everytime the app runs
    public function update() {
        $version = get_option('stuart_plugin_version', NULL);
        if ($version === NULL){
            // 1st time running this plugin on this site
            update_option('stuart_plugin_version', $version);
            // Initialize all the plugin properties
            $fields = $this->get_fields();
            foreach ($fields as $field_name => $field_values) {
                if (isset($field_values['default']) && !empty($field_values['default']) && !isset($new_settings[$field_name])) {
                    $new_settings[$field_name] = $field_values['default'];
                }
            }
            update_option('stuart_plugin_settings', $new_settings);
            $settings = $new_settings;
        }
        // Plugin is up to date
        if ( $version >= $this->version ) :
            // Initialize the Stuart API client
            $stuart_api_client = $this->getStuartAPIClient();
            return;
        endif;
        // In the future we may need to use this method to run migrations
    }

    public function get_fields() {
        // General site info
        $title = get_bloginfo('name');
        $admin_email = get_bloginfo('admin_email');
        $fields = array(
            'enabled' => array(
              'title' => esc_html__('Enable/Disable', 'stuart-delivery') ,
              'type' => 'select',
              'options' => array(
                "yes" => esc_html__('Yes', 'stuart-delivery'),
                "no" => esc_html__('No', 'stuart-delivery')
              ),
              'description' => esc_html__('Enable Stuart Delivery Shipping', 'stuart-delivery'),
              'default' => 'yes',
              'tab' => "basic",
              'multivendor' => true,
            ) ,
            'license_key' => array(
              'title' => esc_html__('Licence key', 'stuart-delivery') ,
              'type' => 'text',
              'description' => '' ,
              'default' => '' ,
              'tab' => "basic",
            ) ,
            'title' => array(
              'title' => esc_html__('Method Title', 'stuart-delivery') ,
              'type' => 'text',
              'description' => esc_html__('This controls the title which the user sees during checkout.', 'stuart-delivery') ,
              'default' => esc_html__('Delivery now with Stuart', 'stuart-delivery') ,
              'tab' => "basic",
            ) ,
            'api_id' => array(
              'title' => esc_html__('Stuart client API ID', 'stuart-delivery') ,
              'type' => 'text',
              'description' => '' ,
              'default' => '' ,
              'tab' => "basic",
            ) ,
            'api_secret' => array(
              'title' => esc_html__('Stuart client API Secret', 'stuart-delivery') ,
              'type' => 'text',
              'description' => '' ,
              'default' => '' ,
              'tab' => "basic",
            ) ,
            'price_type' => array(
              'title' => esc_html__('What is the payment fee type ?', 'stuart-delivery') ,
              'type' => 'select',
              'description' => esc_html__('Enable Customer to pay for delivery', 'stuart-delivery') ,
              'default' => 'added',
              'tab' => "basic",
              'options'       => array(
                  'added'   => esc_html__('Add the fee above from Stuart quote', 'stuart-delivery'),
                  'removed' => esc_html__('Remove the fee above from Stuart quote', 'stuart-delivery'),
                  'fixed' => esc_html__('Use fixed fee above', 'stuart-delivery'),
                  'percentage_remove' => esc_html__('Remove percentage after a certain cart price', 'stuart-delivery'),
                  'percentage_add' => esc_html__('Add percentage after a certain cart price', 'stuart-delivery'),
                  'multiple' => esc_html__('Multiple number below price from stuart quote', 'stuart-delivery'),
                  'multiple_cart_remove' => esc_html__('Remove multiple of cart price from Stuart quote', 'stuart-delivery'),
                  'multiple_cart_add' => esc_html__('Add multiple of cart price from Stuart quote', 'stuart-delivery'),
              ),
            ) ,
            'price' => array(
              'title' => esc_html__('What is the delivery fee added/removed/multiplied from Stuart quote ?', 'stuart-delivery') ,
              'type' => 'text',
              'description' => esc_html__('Add/remove 0.00 of your currency, value is a percentage if selected on price type above, add 10.00 for 10%.', 'stuart-delivery') ,
              'default' => '0.00',
              'tab' => "basic",
            ) ,
            'free_shipping' => array(
              'title' => esc_html__('Do you offer delivery after some price (tax included) ?', 'stuart-delivery') ,
              'type' => 'text',
              'description' => esc_html__('0.00 to disable, also applied as percentage threshold if selected above', 'stuart-delivery') ,
              'default' => '0.00',
              'tab' => "basic",
            ) ,
            'min_price' => array(
              'title' => esc_html__('Do you accept Stuart deliveries only after a certain amount (cart, tax included) ?', 'stuart-delivery') ,
              'type' => 'text',
              'description' => esc_html__('0.00 to disabled=, also applied as percentage threshold if selected above', 'stuart-delivery') ,
              'default' => '0.00',
              'tab' => "basic",
            ) ,
            'max_price' => array(
              'title' => esc_html__('Do you want to limit price to a maximum ?', 'stuart-delivery') ,
              'type' => 'text',
              'description' => esc_html__('0.00 to disable', 'stuart-delivery') ,
              'default' => '0.00',
              'tab' => "basic",
            ) ,
            'max_serve_price' => array(
              'title' => esc_html__('Do you want to disable stuart delivery option after a certain price ?', 'stuart-delivery') ,
              'type' => 'text',
              'description' => esc_html__('0.00 to disable', 'stuart-delivery') .'. '. esc_html__('Price from the API, not from rules above.', 'stuart-delivery') ,
              'default' => '0.00',
              'tab' => "basic",
            ) ,
            'tax_type' => array(
              'title' => esc_html__('Do you apply taxes to Stuart Shipping ?', 'stuart-delivery') ,
              'type' => 'select',
              'default' => 'default',
              'tab' => "basic",
              'options'       => array(
                  'default'   => esc_html__('Use WooCommerce settings', 'stuart-delivery'),
                  'add_percentage' => esc_html__('Add percentage below', 'stuart-delivery'),
              ),
            ) ,
            'tax_percentage' => array(
              'title' => esc_html__('What percentage of tax do you apply ?', 'stuart-delivery') ,
              'type' => 'text',
              'description' => esc_html__('0.00 to disable', 'stuart-delivery') ,
              'default' => '0.00',
              'tab' => "basic",
            ) ,
            'address' => array(
              'title' => esc_html__('Address of your store', 'stuart-delivery') ,
              'type' => 'text',
              'description' => esc_html__('Street, building', 'stuart-delivery') ,
              'default' => '' ,
              'tab' => "basic",
            ) ,
            'address_2' => array(
              'title' => esc_html__('Address 2 of your store', 'stuart-delivery') ,
              'type' => 'text',
              'description' => esc_html__('Office number or floor or flat', 'stuart-delivery') ,
              'default' => '' ,
              'tab' => "basic",
            ) ,
            'postcode' => array(
              'title' => esc_html__('Your store ZIP/Post Code', 'stuart-delivery') ,
              'type' => 'text',
              'default' => '',
              'tab' => "basic",
            ) ,
            'city' => array(
              'title' => esc_html__('Your store city name', 'stuart-delivery') ,
              'type' => 'text',
              'default' => '',
              'tab' => "basic",
            ) ,
            'country' => array(
              'title' => esc_html__('Your store country name (in local language)', 'stuart-delivery') ,
              'type' => 'text',
              'default' => 'France',
              'tab' => "basic",
            ) ,
            'first_name' => array(
              'title' => esc_html__('Pickup info: first name', 'stuart-delivery') ,
              'type' => 'text',
              'description' => esc_html__('First name of person to pickup products (i.e. your manager)', 'stuart-delivery') ,
              'default' => '' ,
              'tab' => "basic",
            ) ,
            'last_name' => array(
              'title' => esc_html__('Pickup info: last name', 'stuart-delivery') ,
              'type' => 'text',
              'description' => esc_html__('Last name of person to pickup products (i.e. your manager)', 'stuart-delivery') ,
              'default' => '' ,
              'tab' => "basic",
            ) ,
            'company_name' => array(
              'title' => esc_html__('Pickup info: company name', 'stuart-delivery') ,
              'type' => 'text',
              'description' => esc_html__('Name of your company', 'stuart-delivery') ,
              'default' => esc_html__($blog_title, 'stuart-delivery') ,
              'tab' => "basic",
            ) ,
            'email' => array(
              'title' => esc_html__('Pickup info: e-mail', 'stuart-delivery') ,
              'type' => 'text',
              'description' => esc_html__('E-mail of your company/site/person', 'stuart-delivery') ,
              'default' => esc_html__($blog_email, 'stuart-delivery') ,
              'tab' => "basic",
            ) ,
            'phone' => array(
              'title' => esc_html__('Pickup info: phone number', 'stuart-delivery') ,
              'type' => 'text',
              'description' => esc_html__('Phone number of your company/site/person', 'stuart-delivery') ,
              'default' => '' ,
              'tab' => "basic",
            ) ,
            'comment' => array(
              'title' => esc_html__('Pickup info: special instructions', 'stuart-delivery') ,
              'type' => 'text',
              'description' => esc_html__('Some special instructions for Stuart courier to pickup', 'stuart-delivery') ,
              'default' => '' ,
              'tab' => "basic",
              'multivendor' => true,
            ) ,
            'only_postcodes' => array(
              'title' => esc_html__('Filter delivery by postcodes', 'stuart-delivery') ,
              'type' => 'text',
              'description' => esc_html__('Prevent delivery in some unexpected zones by allowing only certain postcodes. Indicate them like this "123456,1245367,21688" (separated by comma, no space). Leave empty to disable.', 'stuart-delivery') ,
              'default' => '' ,
              'tab' => "basic",
            ) ,
        );
        $fields['delay'] = array(
            'title' => esc_html__('What is the delay to prepare the order ?', 'stuart-delivery') ,
            'type' => 'text',
            'default' => '30' ,
            'description' => esc_html__('Prevent courier to come before you can prepare by adding minutes here.', 'stuart-delivery') ,
            'tab' => "hours",
            'multivendor' => true,
        );
        // Weekdays info
        $days_array = array(
            "monday" => esc_html__('Monday', 'stuart-delivery') ,
            "tuesday" => esc_html__('Tuesday', 'stuart-delivery') ,
            "wednesday" => esc_html__('Wednesday', 'stuart-delivery') ,
            "thursday" => esc_html__('Thursday', 'stuart-delivery') ,
            "friday" => esc_html__('Friday', 'stuart-delivery') ,
            "saturday" => esc_html__('Saturday', 'stuart-delivery') ,
            "sunday" => esc_html__('Sunday', 'stuart-delivery') ,
        );
        foreach ($days_array as $day_name => $day_translation) {
        $fields['working_'.$day_name] = array(
              'title' => sprintf(esc_html__('Are you working on %s', 'stuart-delivery'), $day_translation),
              'type' => 'select',
              'default' => (in_array($day_name, array('pause', 'sunday', 'saturday')) ? 'no' : 'yes') ,
              'options' => array(
                "yes" => esc_html__('Yes', 'stuart-delivery'),
                "no" => esc_html__('No', 'stuart-delivery')
              ),
              'tab' => "hours",
              'multivendor' => true,
        );
        $fields['lowest_hour_'.$day_name] = array(
              'title' => sprintf(esc_html__('At what time do you start on %s ?', 'stuart-delivery'), $day_translation) ,
              'type' => 'text',
              'default' => '9:30',
              'description' => esc_html__('Format this in 24H format 23:23 for 11PM 23 minutes', 'stuart-delivery') ,
              'tab' => "hours",
              'multivendor' => true,
        );
        $fields['highest_hour_'.$day_name] = array(
              'title' => sprintf(esc_html__('At what time do you stop on %s ?', 'stuart-delivery'), $day_translation) ,
              'type' => 'text',
              'default' => '21:30' ,
              'description' => esc_html__('Format this in 24H format 23:23 for 11PM 23 minutes', 'stuart-delivery') ,
              'tab' => "hours",
              'multivendor' => true,
        );
        $fields['lowest_hour_pause_'.$day_name] = array(
              'title' => sprintf(esc_html__('At what time do you start your pause on %s ?', 'stuart-delivery'), $day_translation) ,
              'type' => 'text',
              'default' => '00:00',
              'description' => esc_html__('Leave 00:00 to disable.', 'stuart-delivery').' '.esc_html__('Format this in 24H format 23:23 for 11PM 23 minutes', 'stuart-delivery') ,
              'tab' => "hours",
              'multivendor' => true,
        );
        $fields['highest_hour_pause_'.$day_name] = array(
              'title' => sprintf(esc_html__('At what time do you stop your pause on %s ?', 'stuart-delivery'), $day_translation),
              'type' => 'text',
              'default' => '00:00' ,
              'description' => esc_html__('Leave 00:00 to disable.', 'stuart-delivery').' '.esc_html__('Format this in 24H format 23:23 for 11PM 23 minutes', 'stuart-delivery') ,
              'tab' => "hours",
              'multivendor' => true,
        );
          }
        $fields['holidays'] = array(
            'title' =>  esc_html__('Holidays', 'stuart-delivery') ,
            'type' => 'text',
            'default' => '1/01,11/11,18/05' ,
            'description' => esc_html__('Indicate day and month and separate by coma, for example :', 'stuart-delivery') . ' 1/01,11/11,18/05',
            'tab' => "hours",
            'multivendor' => true,
        );
        // Product categories info
        $cat_args = array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        );
        $product_categories = get_terms($cat_args);
        $categories = array(0 => esc_html__('No', 'stuart-delivery'));
        if (!empty($product_categories)) {
            foreach ($product_categories as $key => $category) {
              if (is_object($category) && isset($category->term_id)) {
                $categories[$category->term_id] = esc_html($category->name);
              }
            }
        }
        $fields['excluded_categories'] = array(
            'type' => 'select',
            'title' => esc_html__('Product category excluded from this delivery method', 'stuart-delivery'),
            'description' => esc_html__('Do you want to prevent products in a category to be delivered with Stuart ? Only categories with products are displayed here.', 'stuart-delivery'),
            'default' => '0',
            'tab' => 'advanced',
            'options' => $categories
        );
        // Google Maps integration info
        $fields['display_maps_link'] = array(
            'title' => esc_html__('Display a link to courier route on checkout', 'stuart-delivery') ,
            'type' => 'select',
            'options' => array(
                "yes" => esc_html__('Yes', 'stuart-delivery'),
                "no" => esc_html__('No', 'stuart-delivery')
              ),
            'description' => esc_html__('Display a link to see future courier route (via Google Maps) on frontend under time selection.', 'stuart-delivery') ,
            'default' => 'no',
            'tab' => "advanced",
        );
        $fields['display_maps_iframe'] = array(
            'title' => esc_html__('Display a map of the courier route on checkout', 'stuart-delivery') ,
            'type' => 'select',
            'options' => array(
                "yes" => esc_html__('Yes', 'stuart-delivery'),
                "no" => esc_html__('No', 'stuart-delivery')
              ),
            'description' => esc_html__('Display a small map within an iframe.', 'stuart-delivery') ,
            'default' => 'no',
            'tab' => "advanced",
        );
        $fields['maps_api_key_iframe'] = array(
            'title' =>  esc_html__('Google Maps - Embed API - API Key', 'stuart-delivery') ,
            'type' => 'text',
            'value' => 'yes' ,
            'default' => '' ,
            'description' => esc_html__('Needed for coursier route on maps on checkout. Do not forget to limit to your domain only, the API key is public.', 'stuart-delivery') . ' <a href="https://console.cloud.google.com/apis/library/maps-embed-backend.googleapis.com" target="_blank">https://console.cloud.google.com/apis/library/maps-embed-backend.googleapis.com</a>',
            'tab' => "advanced",
        );
        // Stuart custom settings
        $fields['create_delivery'] = array(
            'title' => esc_html__('Action to auto create Stuart Delivery', 'stuart-delivery') ,
            'type' => 'select',
            'options' => array(
              'manual' => 'Manual only',
              'created' => 'Create order',
            ) ,
            'description' => esc_html__('Choose action to create Stuart Delivery', 'stuart-delivery') ,
            'default' => 'created',
            'tab' => "advanced",
            'multivendor' => true,
        );
        $fields['delivery_mode'] = array(
            'title' => esc_html__('Force delivery mode, only available in France', 'stuart-delivery') ,
            'type' => 'select',
            'options' => array(
                'bike' => esc_html__('Bike', 'stuart-delivery'),
                'motorbike' => esc_html__('Motor Bike', 'stuart-delivery'),
                'motorbikexl' => esc_html__('Motor Bike XL', 'stuart-delivery'),
                'cargobike' => esc_html__('Cargo Bike', 'stuart-delivery'),
                'cargobikexl' => esc_html__('Cargo Bike XL', 'stuart-delivery'),
                'car' => esc_html__('Car', 'stuart-delivery'),
                'van' => esc_html__('Van', 'stuart-delivery'),
            ) ,
            'description' => esc_html__('Force delivery carrier on Stuart Delivery', 'stuart-delivery') ,
            'default' => 'bike',
            'tab' => "advanced",
        );

        $fields['force_package'] = array(
            'title' => esc_html__('Force package type below ? available outside france', 'stuart-delivery') ,
            'type' => 'select',
            'options' => array(
                "yes" => esc_html__('Yes', 'stuart-delivery'),
                "no" => esc_html__('No', 'stuart-delivery')
              ),
            'default' => 'no',
            'description' => esc_html__('Choose action to create Stuart Delivery', 'stuart-delivery') ,
            'tab' => "advanced",
        );

        $fields['force_package_type'] = array(
            'title' => esc_html__('Force delivery package size, use with caution', 'stuart-delivery') ,
            'type' => 'select',
            'options' => array(
                'small' => esc_html__('Small', 'stuart-delivery'),
                'medium' => esc_html__('Medium', 'stuart-delivery'),
                'large' => esc_html__('Large', 'stuart-delivery'),
                'xlarge' => esc_html__('X Large', 'stuart-delivery'),
            ) ,
            'description' => esc_html__('Force delivery carrier on Stuart Delivery', 'stuart-delivery') ,
            'default' => 'small',
            'tab' => "advanced",
        );

        $fields['start_delivery_on_order_pending'] = array(
            'title' => esc_html__('Start Stuart Delivery on order pending payment (if paid after delivery for example)', 'stuart-delivery') ,
            'type' => 'select',
            'options' => array(
                "yes" => esc_html__('Yes', 'stuart-delivery'),
                "no" => esc_html__('No', 'stuart-delivery')
              ),
            'description' => esc_html__('Enable to start Stuart delivery, when order is on pending', 'stuart-delivery') ,
            'default' => 'yes',
            'tab' => "advanced",
        );

        $fields['start_delivery_on_order_processing'] = array(
            'title' => esc_html__('Start Stuart Delivery on order processing (after it is paid)', 'stuart-delivery') ,
            'type' => 'select',
            'options' => array(
                "yes" => esc_html__('Yes', 'stuart-delivery'),
                "no" => esc_html__('No', 'stuart-delivery')
              ),
            'description' => esc_html__('Enable to start Stuart delivery, when order is on processing', 'stuart-delivery') ,
            'default' => 'yes',
            'tab' => "advanced",
        );

        $fields['cancel_delivery_on_order_cancel'] = array(
            'title' => esc_html__('Cancel Stuart Delivery on order cancelation', 'stuart-delivery') ,
            'type' => 'select',
            'options' => array(
                "yes" => esc_html__('Yes', 'stuart-delivery'),
                "no" => esc_html__('No', 'stuart-delivery')
              ),
            'description' => esc_html__('Enable to cancel Stuart delivery, when order is canceled', 'stuart-delivery') ,
            'default' => 'yes',
            'tab' => "advanced",
        );

        $fields['cancel_delivery_on_order_refund'] = array(
            'title' => esc_html__('Cancel Stuart Delivery on order refunded', 'stuart-delivery') ,
            'type' => 'select',
            'options' => array(
                "yes" => esc_html__('Yes', 'stuart-delivery'),
                "no" => esc_html__('No', 'stuart-delivery')
              ),
            'description' => esc_html__('Enable to cancel Stuart delivery, when order is refunded', 'stuart-delivery') ,
            'default' => 'yes',
            'tab' => "advanced",
        );

        $fields['days_limit'] = array(
            'title' => esc_html__('Limit days to schedule order', 'stuart-delivery') ,
            'type' => 'text',
            'default' => '21' ,
            'description' => esc_html__('3 weeks by default. Cannot be shorter than 1.', 'stuart-delivery') ,
            'tab' => "advanced",
        );

        $fields['delay_type'] = array(
              'title' => esc_html__('Does time delay apply at business opening each day or at the moment of order ?', 'stuart-delivery') ,
              'type' => 'select',
              'options' => array(
                  '0' => esc_html__('No', 'stuart-delivery'),
                  '1' => esc_html__('Yes, only for next business day', 'stuart-delivery'),
                  '2' => esc_html__('Yes, for all business days', 'stuart-delivery'),
              ) ,
              'description' => esc_html__('If not, it will be applied only at time of order, for instance order placed at night might be delivered at business opening.', 'stuart-delivery') ,
              'default' => '0',
            'tab' => "advanced",
        );

        $fields['same_day_limit'] = array(
            'title' => esc_html__('Stop displaying same day order after :', 'stuart-delivery') ,
            'type' => 'text',
            'default' => '00:00' ,
            'description' => esc_html__('Avoid before closing orders. Leave 00:00 to disable.', 'stuart-delivery').esc_html__('Format this in 24H format 23:23 for 11PM 23 minutes', 'stuart-delivery') ,
            'tab' => "advanced",
        );

        $fields['same_day_delivery_start'] = array(
            'title' => esc_html__('Same day delivery starts after :', 'stuart-delivery') ,
            'type' => 'text',
            'default' => '00:00' ,
            'description' => esc_html__('Avoid before opening orders, replace current day start hour. Leave 00:00 to disable.', 'stuart-delivery').esc_html__('Format this in 24H format 23:23 for 11PM 23 minutes', 'stuart-delivery') ,
            'tab' => "advanced",
        );

        $fields['same_day_delivery_end'] = array(
            'title' => esc_html__('Same day delivery stops after :', 'stuart-delivery') ,
            'type' => 'text',
            'default' => '00:00' ,
            'description' => esc_html__('Avoid late deliveries orders, replace current day stop hour. Leave 00:00 to disable.', 'stuart-delivery').esc_html__('Format this in 24H format 23:23 for 11PM 23 minutes', 'stuart-delivery') ,
            'tab' => "advanced",
        );

        $fields['late_order_next_day_mode'] = array(
            'title' => esc_html__('Do we push next day delivery hours when shop is closed before midnight ') ,
            'type' => 'select',
            'options' => array(
                'yes' => esc_html__('Yes', 'stuart-delivery'),
                'no' => esc_html__('No', 'stuart-delivery'),
            ) ,
            'description' => esc_html__('Only apply if same day delivery hours are modified.', 'stuart-delivery') ,
            'default' => 'yes',
            'tab' => "advanced",
        );

        $fields['no_pricing_message'] = array(
            'title' => esc_html__('Display a text on checkout if Stuart is not available for this area.', 'stuart-delivery') ,
            'type' => 'text',
            'default' => '' ,
            'description' => esc_html__('Disabled if empty.', 'stuart-delivery') ,
            'tab' => "advanced",
        );

        // Hooks
        $hooks = array(
            'woocommerce_after_shipping_rate' => esc_html__('After shipping rate', 'stuart-delivery'),
            'woocommerce_review_order_after_shipping' => esc_html__('Review Order, after shipping', 'stuart-delivery'),
            'woocommerce_review_order_after_cart_contents' => esc_html__('Review Order, after cart content', 'stuart-delivery'),
            'woocommerce_review_order_before_order_total' => esc_html__('Review Order, before order total', 'stuart-delivery'),
            'woocommerce_review_order_after_order_total' => esc_html__('Review Order, after order total', 'stuart-delivery'),
            'woocommerce_review_order_before_payment' => esc_html__('Review Order, before Payment', 'stuart-delivery'),
            'woocommerce_review_order_after_payment' => esc_html__('Review Order, after payment', 'stuart-delivery'),
            'woocommerce_review_order_before_total' => esc_html__('Review Order, before total', 'stuart-delivery'),
            'woocommerce_review_order_before_total' => esc_html__('Review Order, before total', 'stuart-delivery'),
            'woocommerce_review_order_before_shipping' => esc_html__('Review Order, before shipping', 'stuart-delivery'),
            'woocommerce_review_order_before_submit' => esc_html__('Review Order, before submit', 'stuart-delivery'),
            'woocommerce_checkout_before_order_review' => esc_html__('Checkout before Order review', 'stuart-delivery'),
            'wfacp_before_payment_section' => esc_html__('WooFunnel before payment', 'stuart-delivery'),
        );

        $fields['hook_frontend_desktop'] = array(
            'title' => esc_html__('Which frontend hook (desktop) does you want to use to display Stuart delivery scheduling ?', 'stuart-delivery') ,
            'type' => 'select',
            'options' => $hooks,
            'description' => esc_html__('Force display on certain hooks on checkout, permit to put frontend scheduling where you want.', 'stuart-delivery') ,
            'default' => 'woocommerce_after_shipping_rate',
            'tab' => "advanced",
        );

        $fields['hook_frontend_mobile'] = array(
              'title' => esc_html__('Which frontend hook (mobile) does you want to use to display Stuart delivery scheduling ?', 'stuart-delivery') ,
              'type' => 'select',
              'options' => $hooks,
              'description' => esc_html__('Force display on certain hooks on checkout, permit to put frontend scheduling where you want.', 'stuart-delivery') ,
              'default' => 'woocommerce_after_shipping_rate',
              'tab' => "advanced",
        );

        $fields['prevent_checkout_update'] = array(
            'title' => esc_html__('Prevent checkout update on hour change') ,
            'type' => 'select',
            'options' => array(
                'yes' => esc_html__('Yes', 'stuart-delivery'),
                'no' => esc_html__('No', 'stuart-delivery'),
            ) ,
            'description' => esc_html__('If your checkout does not refresh properly when you change hour on shipping options, it might be better to prevent refresh. Hours will still be ok, however pricing might change slightly in some cases.', 'stuart-delivery') ,
            'default' => 'no',
            'tab' => "advanced",
        );

        // Environment
        $fields['env'] = array(
            'title' =>  esc_html__('Is this a production account ?', 'stuart-delivery') ,
            'type' => 'select',
            'options' => array(
                "yes" => esc_html__('Yes', 'stuart-delivery'),
                "no" => esc_html__('No', 'stuart-delivery')
              ),
            'value' => 'yes' ,
            'default' => 'yes' ,
            'description' => esc_html__('Uncheck to use sandbox version', 'stuart-delivery') ,
            'tab' => "advanced",
        );

        $fields['debug_mode'] = array(
            'title' =>  esc_html__('Debug logs', 'stuart-delivery') ,
            'type' => 'select',
            'options' => array(
                "yes" => esc_html__('Yes', 'stuart-delivery'),
                "no" => esc_html__('No', 'stuart-delivery')
              ),
            'value' => 'yes' ,
            'default' => 'no' ,
            'description' => esc_html__('It will creates more logs and allow you to have a better insight of what is happening. Logs are encrypted and deleted after one month for security purpose as they might contain personnal data and be elligible to GDPR rules.', 'stuart-delivery') ,
            'tab' => "advanced",
        );

        $fields = apply_filters('stuart_settings_fields', $fields);

        return $fields;

    }

    // Singleton
    public static function getInstance() {
		if ( !self::$instance )
			self::$instance = new self;
		return self::$instance;
	}

}

// Instantiate our class
$Stuart = Stuart::getInstance();