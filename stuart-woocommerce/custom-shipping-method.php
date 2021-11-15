<?php

require_once(WP_CONTENT_DIR . '/plugins/stuart-woocommerce/interfaces/stuart-shipping-method.php');

if (! class_exists('StuartShippingMethod')) {
    class StuartShippingMethod extends WC_Shipping_Method implements StuartCustomShippingMethod
    {
        public function __construct($instance_id = 0)
        {
            load_plugin_textdomain('stuart-delivery', false, basename(dirname(__FILE__)) . '/languages');
            
            $this->id = 'StuartShippingMethod';
            $this->instance_id = absint($instance_id);
            $this->title = $this->getOption('title');
            $this->method_title = esc_html__('Stuart Delivery', 'stuart-delivery');
            $this->method_description = esc_html__('Delivery on schedule.', 'stuart-delivery');
            $this->enabled = $this->getOption('enabled');
            $this->table_name = 'stuart_logs';
            $this->supports = array(
                'shipping-zones',
                'settings',
            );

            $this->init_form_fields();
            $this->init_settings();
            $this->request_processed = array();

            add_action('woocommerce_update_options_shipping_' . $this->id, array(
              $this,
              'process_admin_options'
            ));

            $this->api_client_id = $this->getOption('api_id');
            $this->api_client_ps = $this->getOption('api_secret');
            $this->time_list_cache = null;
        }

        public function purgeLogs()
        {
            global $wpdb;
            $wpdb->query("DELETE FROM ". $wpdb->prefix . $this->table_name . " WHERE date_created < DATE_SUB(NOW(),INTERVAL 1 MONTH)");
        }
 
        private function encrypt($string, $action = 'e')
        {
            if (! function_exists('openssl_encrypt')) {
                return $string;
            }

            $secret_key = $this->getOption('api_id');
            $secret_iv = $this->getOption('api_secret');
         
            $output = false;
            $encrypt_method = "AES-256-CBC";
            $key = hash('sha256', $secret_key);
            $iv = substr(hash('sha256', $secret_iv), 0, 16);
         
            if ($action == 'e') {
                $output = openssl_encrypt(serialize($string), $encrypt_method, $key, 0, $iv);
            } elseif ($action == 'd') {
                $output = unserialize(openssl_decrypt($string, $encrypt_method, $key, 0, $iv));
            }
         
            return $output;
        }

        private function decrypt($datas)
        {
            return $this->encrypt($datas, 'd');
        }

        public function addLog($type = '', $content)
        {
            global $wpdb;

            if (is_array($content) || is_object($content)) {
                $content = serialize($content);
            } else {
                $content = sanitize_text_field($content);
            }

            $content = $this->encrypt($content);

            $wpdb->insert($wpdb->prefix . $this->table_name, array('type' => sanitize_text_field($type), 'content' => $content, 'date_created' => date('Y-m-d H:i:s') ));
        }

        private function getLog($log_id)
        {
            global $wpdb;

            $res = $wpdb->prepare("SELECT * FROM ". $wpdb->prefix . $this->table_name . " WHERE log_id = %d", $log_id);

            return $wpdb->get_row($res, ARRAY_A);
        }

        private function findLogs($offset = 0, $limit = 10000)
        {
            global $wpdb;

            $res = $wpdb->prepare("SELECT * FROM ". $wpdb->prefix . $this->table_name . " WHERE log_id > 0 ORDER BY log_id DESC LIMIT %d, %d", $offset, $limit);

            return $wpdb->get_results($res, ARRAY_A);
        }

        private function deleteLog($log_id)
        {
            global $wpdb;

            $row = $this->get($log_id);
            
            $wpdb->delete($wpdb->prefix.$this->table_name, ['log_id' => (int) $log_id]);
        }
        
        public function getFields()
        {
            // General site info
            $title             = get_bloginfo('name');
            $admin_email       = get_bloginfo('admin_email');
            $blog_title        = get_bloginfo('name');
            $store_address     = get_option('woocommerce_store_address');
            $store_address_2   = get_option('woocommerce_store_address_2');
            $store_city        = get_option('woocommerce_store_city');
            $store_postcode    = get_option('woocommerce_store_postcode');
            $store_raw_country = get_option('woocommerce_default_country');
            // Split the country/state
            $split_country = explode(":", $store_raw_country);
            // Country and state separated:
            $store_country = $split_country[0];
            $store_state   = $split_country[1];
            $fields = array(
              'enabled' => array(
                'title' => esc_html__('Enable?', 'stuart-delivery') ,
                'type' => 'checkbox',
                'label' => esc_html__('Enable Stuart Delivery Shipping', 'stuart-delivery'),
                'default' => 'yes',
                'tab' => "basic",
                'multivendor' => true,
                'header' => false,
              ) ,
              'title' => array(
                'title' => esc_html__('Method Title', 'stuart-delivery') ,
                'type' => 'text',
                'description' => esc_html__('This is the title that the user sees during checkout.', 'stuart-delivery') ,
                'default' => esc_html__('Delivery now with Stuart', 'stuart-delivery') ,
                'tab' => "basic",
                'header' => false,
              ) ,
              'api_id' => array(
                'title' => esc_html__('Stuart client API ID', 'stuart-delivery') ,
                'type' => 'text',
                'description' => '' ,
                'default' => '' ,
                'tab' => "basic",
                'header' => false,
              ) ,
              'api_secret' => array(
                'title' => esc_html__('Stuart client API Secret', 'stuart-delivery') ,
                'type' => 'text',
                'description' => '' ,
                'default' => '' ,
                'tab' => "basic",
                'header' => false,
              ) ,
              'price_type' => array(
                'title' => esc_html__('How is the shipping fee calculated?', 'stuart-delivery') ,
                'type' => 'select',
                'description' => esc_html__('', 'stuart-delivery') ,
                'default' => 'autocalculated',
                'class' => 'price_type',
                'tab' => 'basic',
                'header' => 'Pricing',
                'options' => array(
                    'autocalculated' => esc_html__('Calculated by Stuart API', 'stuart-delivery'),
                    'fixed' => esc_html__('Use a fixed fee', 'stuart-delivery'),
                    'add_over_stuart' => esc_html__('Add an extra fee over Stuart price', 'stuart-delivery'),
                    'discount_from_stuart' => esc_html__('Discount an amount from Stuart price', 'stuart-delivery'),
                ),
              ),
              'fixed_fee' => array(
                'title' => esc_html__('Shipping fee', 'stuart-delivery') ,
                'type' => 'text',
                'description' => esc_html__('', 'stuart-delivery') ,
                'class' => 'fixed',
                'default' => '0.00',
                'header' => false,
                'tab' => "basic",
              ) ,
              'extra_price' => array(
                'title' => esc_html__('How much to add over Stuart prices?', 'stuart-delivery') ,
                'type' => 'text',
                'description' => esc_html__('Units are relative to Stuart API prices. For example: 10 is 10% over Stuart price', 'stuart-delivery') ,
                'class' => 'add_over_stuart',
                'default' => '0',
                'header' => false,
                'tab' => "basic",
              ) ,
              'discount_price' => array(
                'title' => esc_html__('How much to discount from Stuart prices?', 'stuart-delivery') ,
                'type' => 'text',
                'description' => esc_html__('Units are relative to Stuart API prices. For example: 10 is 10% over Stuart price', 'stuart-delivery') ,
                'default' => '0',
                'class' => 'discount_from_stuart',
                'header' => false,
                'tab' => "basic",
              ) ,
              'free_shipping' => array(
                'title' => esc_html__('Do you offer free delivery after some price (tax included) ?', 'stuart-delivery') ,
                'type' => 'text',
                'description' => esc_html__('0.00 to disable', 'stuart-delivery') ,
                'default' => '0.00',
                'header' => false,
                'tab' => "basic",
              ) ,
              'address' => array(
                'title' => esc_html__('Address of your store', 'stuart-delivery') ,
                'type' => 'text',
                'header' => 'Address',
                'description' => esc_html__('Street, building', 'stuart-delivery') ,
                'default' => esc_html__($store_address, 'stuart-delivery') ,
                'tab' => "basic",
              ) ,
              'address_2' => array(
                'title' => esc_html__('Address 2 of your store', 'stuart-delivery') ,
                'type' => 'text',
                'header' => false,
                'description' => esc_html__('Office number or floor or flat', 'stuart-delivery') ,
                'default' => esc_html__($store_address_2, 'stuart-delivery') ,
                'tab' => "basic",
              ) ,
              'postcode' => array(
                'title' => esc_html__('Your store ZIP/Post Code', 'stuart-delivery') ,
                'type' => 'text',
                'header' => false,
                'default' => esc_html__($store_postcode, 'stuart-delivery') ,
                'tab' => "basic",
              ) ,
              'city' => array(
                'title' => esc_html__('Your store city name', 'stuart-delivery') ,
                'type' => 'text',
                'header' => false,
                'default' => esc_html__($store_city, 'stuart-delivery') ,
                'tab' => "basic",
              ) ,
              'country' => array(
                'title' => esc_html__('Your store country name (in local language)', 'stuart-delivery') ,
                'type' => 'text',
                'header' => false,
                'default' => esc_html__($store_country, 'stuart-delivery') ,
                'tab' => "basic",
              ) ,
              'first_name' => array(
                'title' => esc_html__('Pickup info: first name', 'stuart-delivery') ,
                'type' => 'text',
                'header' => false,
                'description' => esc_html__('First name of person to pickup products (i.e. your manager)', 'stuart-delivery') ,
                'default' => '' ,
                'tab' => "basic",
              ) ,
              'last_name' => array(
                'title' => esc_html__('Pickup info: last name', 'stuart-delivery') ,
                'type' => 'text',
                'header' => false,
                'description' => esc_html__('Last name of person to pickup products (i.e. your manager)', 'stuart-delivery') ,
                'default' => '' ,
                'tab' => "basic",
              ) ,
              'company_name' => array(
                'title' => esc_html__('Pickup info: company name', 'stuart-delivery') ,
                'type' => 'text',
                'header' => false,
                'description' => esc_html__('Name of your company', 'stuart-delivery') ,
                'default' => esc_html__($blog_title, 'stuart-delivery') ,
                'tab' => "basic",
              ) ,
              'email' => array(
                'title' => esc_html__('Pickup info: e-mail', 'stuart-delivery') ,
                'type' => 'text',
                'header' => false,
                'description' => esc_html__('E-mail of your company/site/person', 'stuart-delivery') ,
                'default' => esc_html__($admin_email, 'stuart-delivery') ,
                'tab' => "basic",
              ) ,
              'phone' => array(
                'title' => esc_html__('Pickup info: phone number', 'stuart-delivery') ,
                'type' => 'text',
                'header' => false,
                'description' => esc_html__('Phone number of your company/site/person', 'stuart-delivery') ,
                'default' => '' ,
                'tab' => "basic",
              ) ,
              'comment' => array(
                'title' => esc_html__('Pickup info: special instructions', 'stuart-delivery') ,
                'type' => 'textarea',
                'description' => esc_html__('Some special instructions for Stuart courier to pickup', 'stuart-delivery') ,
                'default' => '' ,
                'tab' => "basic",
                'header' => false,
                'css' => 'width: 400px',
                'multivendor' => true,
              ) ,
              'only_postcodes' => array(
                'title' => esc_html__('Filter delivery by postcodes', 'stuart-delivery') ,
                'type' => 'textarea',
                'css' => 'width: 400px',
                'header' => false,
                'description' => esc_html__('Prevent delivery in some unexpected zones by allowing only certain postcodes. Indicate them like this "123456,1245367,21688" (separated by comma, no space). Leave empty to disable.', 'stuart-delivery') ,
                'default' => '' ,
                'tab' => "basic",
              ) ,
          );
            $fields['delay'] = array(
              'title' => esc_html__('What is the delay to prepare the order ?', 'stuart-delivery') ,
              'type' => 'text',
              'header' => false,
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
                'header' => false,
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
                'header' => false,
                'default' => '9:30',
                'description' => esc_html__('Format this in 24H format 23:23 for 11PM 23 minutes', 'stuart-delivery') ,
                'tab' => "hours",
                'multivendor' => true,
          );
                $fields['highest_hour_'.$day_name] = array(
                'title' => sprintf(esc_html__('At what time do you stop on %s ?', 'stuart-delivery'), $day_translation) ,
                'type' => 'text',
                'header' => false,
                'default' => '21:30' ,
                'description' => esc_html__('Format this in 24H format 23:23 for 11PM 23 minutes', 'stuart-delivery') ,
                'tab' => "hours",
                'multivendor' => true,
          );
                $fields['lowest_hour_pause_'.$day_name] = array(
                'title' => sprintf(esc_html__('At what time do you start your pause on %s ?', 'stuart-delivery'), $day_translation) ,
                'type' => 'text',
                'default' => '00:00',
                'header' => false,
                'description' => esc_html__('Leave 00:00 to disable.', 'stuart-delivery').' '.esc_html__('Format this in 24H format 23:23 for 11PM 23 minutes', 'stuart-delivery') ,
                'tab' => "hours",
                'multivendor' => true,
          );
                $fields['highest_hour_pause_'.$day_name] = array(
                'title' => sprintf(esc_html__('At what time do you stop your pause on %s ?', 'stuart-delivery'), $day_translation),
                'type' => 'text',
                'default' => '00:00' ,
                'header' => false,
                'description' => esc_html__('Leave 00:00 to disable.', 'stuart-delivery').' '.esc_html__('Format this in 24H format 23:23 for 11PM 23 minutes', 'stuart-delivery') ,
                'tab' => "hours",
                'multivendor' => true,
          );
            }
            $fields['holidays'] = array(
              'title' =>  esc_html__('Holidays', 'stuart-delivery') ,
              'type' => 'text',
              'header' => false,
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
              'header' => false,
              'title' => esc_html__('Product category excluded from this delivery method', 'stuart-delivery'),
              'description' => esc_html__('Do you want to prevent products in a category to be delivered with Stuart ? Only categories with products are displayed here.', 'stuart-delivery'),
              'default' => '0',
              'tab' => 'advanced',
              'options' => $categories
          );
            // Stuart custom settings
            $fields['create_delivery_mode'] = array(
                'title' => esc_html__('When should the Delivery be created?', 'stuart-delivery') ,
                'type' => 'select',
                'header' => false,
                'options' => array(
                    'pending' => esc_html__('When order is received (no payment initiated)', 'stuart-delivery'),
                    'procesing' => esc_html__('When order is on procesing (payment received)', 'stuart-delivery'),
                    'completed' => esc_html__('When order is completed', 'stuart-delivery'),
                    'manual' =>  esc_html__('Create Deliveries manually', 'stuart-delivery'),
                ) ,
                'description' => esc_html__('', 'stuart-delivery') ,
                'default' => 'procesing',
                'tab' => "advanced",
                'multivendor' => true,
            );
            $fields['cancel_delivery_on_order_cancel'] = array(
              'title' => esc_html__('Cancel Stuart Delivery on order cancelation', 'stuart-delivery') ,
              'type' => 'select',
              'header' => false,
              'options' => array(
                  "yes" => esc_html__('Yes', 'stuart-delivery'),
                  "no" => esc_html__('No', 'stuart-delivery')
                ),
              'description' => esc_html__('', 'stuart-delivery') ,
              'default' => 'yes',
              'tab' => "advanced",
          );
            $fields['cancel_delivery_on_order_refund'] = array(
              'title' => esc_html__('Cancel Stuart Delivery on order refunded', 'stuart-delivery') ,
              'type' => 'select',
              'header' => false,
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
              'header' => false,
              'default' => '21' ,
              'description' => esc_html__('3 weeks by default. Cannot be shorter than 1.', 'stuart-delivery') ,
              'tab' => "advanced",
          );
            $fields['env'] = array(
              'title' =>  esc_html__('Is this a production account ?', 'stuart-delivery') ,
              'type' => 'select',
              'header' => 'Environment',
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
              'header' => false,
              'options' => array(
                  "yes" => esc_html__('Yes', 'stuart-delivery'),
                  "no" => esc_html__('No', 'stuart-delivery')
                ),
              'value' => 'yes' ,
              'default' => 'no' ,
              'description' => esc_html__('It will create more logs and allow you to have a better insight of what is happening. Logs are encrypted and deleted after one month for security purpose as they might contain personnal data and be elligible to GDPR rules.', 'stuart-delivery') ,
              'tab' => "advanced",
          );
  
            $fields = apply_filters('stuart_settings_fields', $fields);
  
            return $fields;
        }

        private function get_field_header($field)
        {
            return empty($field['header']) ? '' : '<tr><th scope="row"><hr style="width:100vw"><h1>' . $field['header'] . '</h1></th></tr>';
        }

        public function generate_settings_html($form_fields = array(), $echo = true)
        {
            if (empty($form_fields)) {
                $form_fields = $this->get_form_fields();
            }
        
            $html = '';
            foreach ($form_fields as $k => $v) {
                $type = $this->get_field_type($v);
                $html .= $this->get_field_header($v);
                if (method_exists($this, 'generate_' . $type . '_html')) {
                    $html .= $this->{'generate_' . $type . '_html'}($k, $v);
                } else {
                    $html .= $this->generate_text_html($k, $v);
                }
            }

            $html .= '<style>.show{display:table-row} .hide{display:none}</style>';
            $html .= '<script>[...document.querySelector(".price_type").options].forEach(e=>{console.log(e.value),"autocalculated"!==e.value&&document.querySelector(".price_type").value!==e.value&&(document.querySelector(`.${e.value}`).closest("tr").classList.remove("show"),document.querySelector(`.${e.value}`).closest("tr").classList.add("hide"))});</script>';
            $html .= '<script>document.querySelector(".price_type").addEventListener("change",e=>{[...document.querySelector(".price_type").options].forEach(e=>{"autocalculated"!==e.value&&(document.querySelector(`.${e.value}`).closest("tr").classList.remove("show"),document.querySelector(`.${e.value}`).closest("tr").classList.add("hide"))}),"autocalculated"!==e.target.value&&(document.querySelector(`.${e.target.value}`).closest("tr").classList.add("show"),document.querySelector(`.${e.target.value}`).closest("tr").classList.remove("hide"))});</script>';
        
            if ($echo) {
                echo $html;
            } else {
                return $html;
            }
        }

        public function admin_options()
        {
            global $wp;
            
            $stuart_token_object = $this->setStuartAuth(true, true); ?>
         
            <h2><?php echo esc_html($this->method_title); ?></h2>
            
            <?php

              if (isset($stuart_token_object->access_token)) {
                  $this->connectedNotice();
              } else {
                  $this->notConnectedNotice($stuart_token_object);
              } ?>

            <?php

              $url = home_url($_SERVER['REQUEST_URI']);
              
            $parsed = parse_url($url);

            $query = $parsed['query'];

            parse_str($query, $params);
              
            if (isset($params['stuart_tab'])) {
                $current_tab = $params['stuart_tab'];
            } else {
                $current_tab = 'basic';
            }

            $final_url = admin_url('admin.php?page=wc-settings&tab=shipping&section=stuartshippingmethod'); ?>

            <nav class="nav-tab-wrapper woo-nav-tab-wrapper">
              <a class="nav-tab <?php echo $current_tab == 'basic' ? 'nav-tab-active' : ''; ?>" href="<?php echo $final_url.'&stuart_tab=basic'; ?>"><?php esc_html_e('Basic settings', 'stuart-delivery'); ?></a>
              <a class="nav-tab <?php echo $current_tab == 'hours' ? 'nav-tab-active' : ''; ?>" href="<?php echo $final_url.'&stuart_tab=hours'; ?>"><?php esc_html_e('Hours & days', 'stuart-delivery'); ?></a>
              <a class="nav-tab <?php echo $current_tab == 'advanced' ? 'nav-tab-active' : ''; ?>" href="<?php echo $final_url.'&stuart_tab=advanced'; ?>"><?php esc_html_e('Advanced configuration', 'stuart-delivery'); ?></a>
              <a class="nav-tab <?php echo $current_tab == 'logs' ? 'nav-tab-active' : ''; ?>" href="<?php echo $final_url.'&stuart_tab=logs'; ?>"><?php esc_html_e('Logs', 'stuart-delivery'); ?></a>
            </nav>

            <table id="stuart-table" class="form-table">
                <?php if ($current_tab == 'logs') : ?>
                    <script>
                        function download_table_as_csv(e=","){for(var t=document.querySelectorAll("table#stuart-table tr"),r=[],a=0;a<t.length;a++){for(var n=[],l=t[a].querySelectorAll("td, th"),o=0;o<l.length;o++){var c=l[o].innerText.replace(/(\r\n|\n|\r)/gm,"").replace(/(\s\s)/gm," ");c=c.replace(/"/g,'""'),n.push('"'+c+'"')}r.push(n.join(e))}var d=r.join("\n"),s="export_stuart_table_"+(new Date).toLocaleDateString()+".csv",u=document.createElement("a");u.style.display="none",u.setAttribute("target","_blank"),u.setAttribute("href","data:text/csv;charset=utf-8,"+encodeURIComponent(d)),u.setAttribute("download",s),document.body.appendChild(u),u.click(),document.body.removeChild(u)}
                    </script>
                    <style>
                        * { margin: 0; padding: 0; }
                        .stuart-button {
                            color: #fff;
                            background-color: #337ab7;
                            border-color: #2e6da4;
                            cursor: pointer;
                            padding: 6px 12px;
                            margin-bottom: 10px;
                            border: 1px solid transparent;
                            border-radius: 4px;
                        }

                        .form-table {
                            background: black;
                            color: #7AFB4C;
                            padding: 8px;
                            overflow: scroll;
                            margin: 15px;
                        }

                        .form-table th {
                            color: white;
                        }
                    </style>
                    <tbody style="display: block; height: 400px; overflow-y: scroll">
                        <button type="button" class="stuart-button" onclick="download_table_as_csv()">Export as CSV</button>
                        <tr>
                            <th>Time</th>
                            <th style="width: 100px;">Type</th>
                            <th>Contents</th>
                        </tr>
                            <?php
                                $this->purgeLogs();
            $logs = $this->findLogs();
            if (!empty($logs)) {
                foreach ($logs as $log) {
                    $content = $this->decrypt($log['content']);
                    $type = $log['type'];
                    $time = $log['date_created'];

                    echo "<tr><td>".$time."</td><td>".$type."</td><td>".$content."</td></tr>";
                }
            }
            echo "<tbody>"; ?>
            
                <?php endif; ?>
                <?php if ($current_tab !== 'logs') {
                $this->generate_settings_html();
            } ?>
            </table>

          <?php
        }

        public function init_form_fields()
        {
            if (isset($_GET['stuart_tab']) && in_array($_GET['stuart_tab'], array('all', 'basic', 'hours', 'advanced'))) {
                $tab = sanitize_text_field($_GET['stuart_tab']);
            } else {
                $tab = 'basic';
            }

            $fields = $this->getFields();

            if ($tab == 'all') {
                $this->form_fields = $fields;
            } else {
                $this->form_fields = array();

                foreach ($fields as $key => $value) {
                    if ($value['tab'] == $tab) {
                        $this->form_fields[$key] = $value;
                    }
                }
            }
        }

        public function is_available($package)
        {
            return true;
        }

        private function getPickupAddress($delivery_address = array())
        {
            if ($this->getOption('address') && $this->getOption('city') && $this->getOption('postcode')) {
                return $this->getOption('address').' , '.($this->getOption('address_2') ? $this->getOption('address_2').' , ' : '').$this->getOption('city').' '.$this->getOption('postcode');
            } else {
                return false;
            }
        }

        public function calculate_shipping($package = array())
        {
            if (!function_exists('getInstance')) {
                require_once 'stuart-woocommerce.php';
            }

            $stuart = Stuart::getInstance();

            if ($this->getOption('enabled') == 'no') {
                return;
            }

            $job_price = (float) $this->getJobPricing($package);

            if (empty($job_price)) {
                return;
            }

            $free_shipping = (float) $this->getOption('free_shipping', $package);
            
            $cart = $stuart->getCart();
            $cart_total_price = (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax();

            if (!empty($free_shipping) && $cart_total_price >= $free_shipping) {
                $price = 0.00;
            } else {
                switch ($this->getOption('price_type', $package)) {
                    case 'autocalculated':
                        $price = $job_price;
                        break;
                    case 'fixed':
                        $price = (float) $this->getOption('fixed_fee', $package);
                        break;
                    case 'add_over_stuart':
                        $price = $job_price;
                        $percentage = (float) $this->getOption('extra_price', $package);
                        if ($percentage > 0.00) {
                            $percentaged_price = $price * ($percentage / 100);
                            $price += $percentaged_price;
                        }
                        break;
                    case 'discount_from_stuart':
                        $price = $job_price;
                        $percentage = (float) $this->getOption('discount_price', $package);
                        if ($percentage > 0.00) {
                            $percentaged_price = $price * ($percentage / 100);
                            $price -= $percentaged_price;
                        }
                        break;
                    default:
                        return;
                        break;
                }
            }

            if ($price < 0.00) {
                $price = 0.00;
            }

            $rate = array(
                'id' => 'stuart:' . get_current_user_id(),
                'label' => $this->getOption('title'),
                'cost' => $price,
              );
  
            $this->add_rate($rate);
        }

        public function getOption($name, $context = false)
        {
            if ($context) {
                $field = apply_filters('stuart_meta_fields', $name, $context);
            
                if ($field != null && $field != $name) {
                    return $field;
                }
            }

            return $this->get_option($name);
        }

        public function updateOption($name, $value)
        {
            return $this->update_option($name, $value);
        }

        private function getOauthToken()
        {
            $url = '/oauth/token';
      
            $params = array(
            "client_id" => $this->api_client_id,
            "client_secret" => $this->api_client_ps,
            "scope" => "api",
            "grant_type" => "client_credentials",
          );

            $obj = $this->makeApiRequest($url, false, $params);

            return $obj;
        }

        public function doPickupTest()
        {
            $pickup_address = $this->getPickupAddress();

            if ((bool) $pickup_address === false) {
                return false;
            } else {
                $url = '/v2/addresses/validate?address='.urlencode($pickup_address).'&type=picking';

                $token = $this->setStuartAuth();

                $obj = $this->makeApiRequest($url, $token);
        
                return $obj;
            }
        }

        public function setStuartAuth($refresh = false, $return_object = false)
        {
            if (!empty($this->api_client_ps) && !empty($this->api_client_id)) {
                $access_token = $this->getOption('token');
                $previous_account = $this->getOption('prev_account');
                $current_account = md5($this->api_client_ps.$this->api_client_id);

                if ($refresh === false && $previous_account == $current_account) {
                    $expired = (time() + 100) >= (int) $this->getOption('token_expire');

                    if ($expired == false) {
                        return $access_token;
                    }
                }

                $obj = $this->getOauthToken();

                if (!empty($obj) && isset($obj->access_token)) {
                    $this->updateOption('token', $obj->access_token);

                    $this->updateOption('token_expire', time() + (int) $obj->expires_in);

                    $current_account = md5($this->api_client_ps.$this->api_client_id);

                    $this->updateOption('prev_account', $current_account);

                    if ($return_object === false) {
                        return $obj->access_token;
                    } else {
                        return $obj;
                    }
                }
            }

            if (isset($obj) && $return_object === true) {
                return $obj;
            } else {
                return false;
            }
        }

        private function makeApiRequest($url, $token = false, $params = array(), $return_array = false, $check_httpcode = false)
        {
            $request = array(
            "url" => $url,
            "params" => $params,
            "token" => $token
        );

            $hash = md5(serialize($request));

            if (isset($this->request_processed[$hash])) {
                return $this->request_processed[$hash];
            }

            $ch = curl_init();
            $headers = array();
            $env = $this->getOption('env') == 'yes' ? 'https://api.stuart.com' : 'https://api.sandbox.stuart.com/' ;

            curl_setopt($ch, CURLOPT_URL, trim($env.$url));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);

            if (!empty($token)) {
                $headers[] = 'Authorization: Bearer '.$token;
            }

            if (!empty($params)) {
                if (is_object($params)) {
                    $fields = json_encode($params);
                    $headers[] = "Content-Type: application/json";
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
                    curl_setopt($ch, CURLOPT_POST, true);
                } elseif (is_array($params)) {
                    $str = array();

                    foreach ($params as $key => $value) {
                        if (!empty($value)) {
                            $str[] = $key.'='.urlencode($value);
                        }
                    }

                    $fields = implode('&', $str);
                    $headers[] = "Content-Type: application/x-www-form-urlencoded";
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
                    curl_setopt($ch, CURLOPT_POST, true);
                } elseif (is_string($params)) {
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                }
            } else {
                if (strpos($url, '/v2/jobs') === false) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, array());
                }
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
            }

            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }

            $res = $response = curl_exec($ch);
            
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($check_httpcode == true) {
                if ((int) $httpcode >= 300) {
                    $response = false;
                } else {
                    if (empty($response)) {
                        $response = true;
                    }
                }
            }

            if ($this->getOption('debug_mode') == "yes" && $token !== false) {
                $log = array('reponse' => $res, 'params' => $params);
                $this->addLog('makeApiRequest::'.$url, $log);
            }
        
            curl_close($ch);

            if (!empty($res)) {
                $object = json_decode($res, $return_array);
                $this->request_processed[$hash] = $object;
            } else {
                $this->request_processed[$hash] = "";
            }

            return $response !== false ? $this->request_processed[$hash] : $response;
        }

        public function getJob($id_job)
        {
            $token = $this->setStuartAuth();

            if (!empty($token) && !empty($id_job)) {
                $request = "/v2/jobs/".$id_job;

                $return = $this->makeApiRequest($request, $token);

                if (!empty($return) && isset($return->id)) {
                    return $return;
                }
            }

            return false;
        }

        public function getJobPricing($object = false)
        {
            $token = $this->setStuartAuth();
          
            $params = $this->prepareJobObject($object);

            if (empty($params)) {
                return false;
            }

            $url = '/v2/jobs/pricing';

            $response = $this->makeApiRequest($url, $token, $params, true, true);

            if (!empty($response) && isset($response['amount'])) {
                return (float) $response['amount'];
            }

            return false;
        }

        private function getPackagesType($products, $is_france = false)
        {
            if (empty($products)) {
                
                // Default is bike
                if ($is_france == true) {
                    return "small";
                } else {
                    return "bike";
                }
            }
        
            $total_weight = $total_depth = $total_height = $total_width = 0.00;

            switch (get_option('woocommerce_weight_unit')) {
              
              case 'g':
                $weight_unit = 0.001;
                break;
              
              case 'lbs':
                $weight_unit = 0.453592;
                break;

              case 'oz':
                $weight_unit = 0.0283495;
                break;
              
              case 'kg':
              default:
                $weight_unit = 1.00;
                break;
            
            }

            switch (get_option('woocommerce_dimension_unit')) {

              case 'm':
                $dimension_unit = 100;
                break;
              
              case 'mm':
                $dimension_unit = 0.1;
                break;

              case 'in':
                $dimension_unit = 2.54;
                break;

              case 'yd':
                $dimension_unit = 91.44;
                break;
              
              case 'cm':
              default:
                $dimension_unit = 1;
                break;
            
            }
            // Using BoxPacker v2 for old PHP Compatibility (5.4+)
            require_once dirname(__FILE__).'/vendor/autoload.php';

            $items = new DVDoug\BoxPacker\ItemList();
                
            $excluded_categories = $this->getOption('excluded_categories');

            foreach ($products as $values) {
                if (is_array($values) && isset($values['product_id'])) {
                    $ipd = $values['product_id'];
                } elseif (is_object($values) && method_exists($values, 'get_product_id')) {
                    $ipd = $values->get_product_id();
                } else {
                    continue;
                }
              
                // Check if not in excluded categories
                if (!empty($excluded_categories)) {
                    $terms = get_the_terms($ipd, 'product_cat');
                    
                    if (!empty($terms)) {
                        foreach ($terms as $term) {
                            if ((int) $excluded_categories == (int) $term->term_id) {
                                return false;
                            }
                        }
                    }
                }

                $_product =  wc_get_product($ipd);
               
                $quantity = (int) $values['quantity'];
               
                if ($quantity == 0) {
                    continue;
                }

                $width = (float) $_product->get_width() * $dimension_unit;
                $depth = (float) $_product->get_length() * $dimension_unit;
                $height = (float) $_product->get_height() * $dimension_unit;
                $weight = (float) $_product->get_weight() * $weight_unit;
                $name = sanitize_text_field($_product->get_formatted_name());

                if ($quantity > 0) {
                    for ($i=0; $i < $quantity; $i++) {
                        /*
                            string $description,
                            int $width,
                            int $length,
                            int $depth,
                            int $weight,
                            bool $keepFlat
                        */
                        $items->insert(new DVDoug\BoxPacker\Test\TestItem($name, $width, $height, $depth, $weight, false));
                    }
                }
            }

            $packages_types = array(
                "small" => array(
                    "width" => 20,
                    "height" => 40,
                    "depth" => 15,
                    "weight" => 3,
                ),
                "medium" => array(
                    "width" => 30,
                    "height" => 50,
                    "depth" => 30,
                    "weight" => 6,
                ),
                "large" => array(
                    "width" => 40,
                    "height" => 90,
                    "depth" => 30,
                    "weight" => 12,
                ),
                "xlarge" => array(
                    "width" => 50,
                    "height" => 120,
                    "depth" => 50,
                    "weight" => 20,
                ),
            );

            $fits_in = false;

            foreach ($packages_types as $type => $sizes) {
                /*
                    string $reference,
                    int $outerWidth,
                    int $outerLength,
                    int $outerDepth,
                    int $emptyWeight,
                    int $innerWidth,
                    int $innerLength,
                    int $innerDepth,
                    int $maxWeight
                */
                $box = new DVDoug\BoxPacker\Test\TestBox($type, $sizes['width'], $sizes['height'], $sizes['depth'], 0.01, $sizes['width'], $sizes['height'], $sizes['depth'], $sizes['weight']);
                $volumePacker = new DVDoug\BoxPacker\VolumePacker($box, $items);
                $packedBox = $volumePacker->pack(); //$packedBox->getItems() contains the items that fit

                if ($items->count() > 0 && $items->count() == $packedBox->getItems()->count()) {
                    $fits_in = $type;
                    break;
                }
            }
            if ($fits_in == false) {
                $log = array('products' => $products);
                  
                $this->addLog('getPackagesType::CartPackageOversize', $log);
            }

            if ($fits_in !== false && $is_france) {
                $french_types = array(
                    "small" => "bike",
                    "medium" => "motorbike",
                    "large" => "cargobike",
                    "xlarge" => "cargobikexl",
                );

                if (isset($french_types[$fits_in])) {
                    return $french_types[$fits_in];
                }
            }

            return $fits_in;
        }

        public function prepareJobObject($object)
        {
            if (is_a($object, 'WC_Order')) {
                $products = $object->get_items();
                $object_id = $object->get_id();
                $customer_id = $object->get_customer_id();
          
                if (empty($customer_id)) {
                    $customer = $object;
                } else {
                    $customer = new WC_Customer($customer_id);
                }
          
                $job_id = $this->getJobId($object_id);
          
                if ($job_id == 0) {
                    // It's a reschedule
                    $object_id = $object_id.'-'.time();
                }
            } elseif (is_a($object, 'WC_Cart')) {
                $products = $object->get_cart();
                $customer = $object->get_customer();
                $object_id = $customer->get_id();

                if (empty($object_id)) {
                    $object_id = time();
                }
            } elseif (is_array($object) && isset($object['contents'])) {
                $products = $object['contents'];
                $cart = $this->getCart();
                $customer = $cart->get_customer();
                $object_id = $customer->get_id();
            } else {
                return false;
            }
            
            if ($customer->get_billing_address_1() != $customer->get_shipping_address_1()) {
                $first_name   = $customer->get_shipping_first_name();
                $last_name    = $customer->get_shipping_last_name();
                $company_name = $customer->get_shipping_company();
                $address_1    = $customer->get_shipping_address_1();
                $address_2    = $customer->get_shipping_address_2();
                $city         = $customer->get_shipping_city();
                $postcode     = $customer->get_shipping_postcode();
                $country      = $customer->get_shipping_country();

                if (empty($first_name)) {
                    $first_name = method_exists($customer, 'get_first_name') ? $customer->get_first_name() : $customer->get_shipping_first_name();
                }

                if (empty($last_name)) {
                    $last_name = method_exists($customer, 'get_last_name') ? $customer->get_last_name() : $customer->get_shipping_last_name();
                }
            } else {
                $first_name   = $customer->get_billing_first_name();
                $last_name    = $customer->get_billing_last_name();
                $company_name = $customer->get_billing_company();
                $address_1    = $customer->get_billing_address_1();
                $address_2    = $customer->get_billing_address_2();
                $city         = $customer->get_billing_city();
                $postcode     = $customer->get_billing_postcode();
                $country      = $customer->get_billing_country();

                if (empty($first_name)) {
                    $first_name = method_exists($customer, 'get_first_name') ? $customer->get_first_name() : $customer->get_billing_first_name();
                }

                if (empty($last_name)) {
                    $last_name = method_exists($customer, 'get_last_name') ? $customer->get_last_name() : $customer->get_billing_last_name();
                }
            }

            $phone = $customer->get_billing_phone();

            $email = method_exists($customer, 'get_email') ? $customer->get_email() : $customer->get_billing_email();

            if (isset($_REQUEST['post_data'])) {
                parse_str(wp_unslash($_REQUEST['post_data']), $datas);

                $things_to_check = array(
                "address_1",
                "address_2",
                "postcode",
                "country",
                "city",
                "first_name",
                "last_name",
              );

                $use_billing = wc_ship_to_billing_address_only() || (isset($_REQUEST['ship_to_different_address']) && !empty($_REQUEST['ship_to_different_address']) && (string) $_REQUEST['ship_to_different_address'] == "1");

                foreach ($things_to_check as $val) {
                    if (!isset($$val) || empty($$val)) {
                        if ($use_billing == true && isset($datas['billing_'.$val]) && !empty($datas['billing_'.$val])) {
                            $$val = sanitize_text_field($datas['billing_'.$val]);
                        } elseif (isset($datas['shipping_'.$val]) && !empty($datas['shipping_'.$val])) {
                            $$val = sanitize_text_field($datas['shipping_'.$val]);
                        }
                    }
                }

                if (empty($phone)) {
                    if (isset($datas['billing_phone']) && !empty($datas['billing_phone'])) {
                        $phone = sanitize_text_field($datas['billing_phone']);
                    }
                }
            } elseif (is_array($object) && isset($object['destination'])) {
                foreach ($object['destination'] as $key => $val) {
                    if (!isset($$key) || empty($$key)) {
                        $$key = sanitize_text_field($val);
                    }
                }
            }

            $pickup_time = $this->getPickupTime($object);

            if ($this->getOption('debug_mode') == "yes") {
                $this->addLog('prepareJobObject::getPickupTime', $pickup_time);
            }

            if (is_numeric($pickup_time) || ($pickup_time != 'now' && $this->validateDate($pickup_time))) {
                if (is_a($object, 'WC_Order') || $this->isDeliveryTime($pickup_time, $object) == true) {
                    if (is_numeric($pickup_time)) {
                        $pickup_date = new DateTime("@".$pickup_time);
                    } else {
                        $pickup_date = new DateTime($pickup_time);
                    }
                    
                    $pickup_date->setTimezone($this->getTimeZone());
                }
            }

            if (!isset($pickup_date)) {
                $time_list = $this->getDeliveryTimeList($object);

                $first_time_available = $this->getFirstDeliveryTime($time_list);

                if ($first_time_available) {
                    $pickup_time = $this->getTime($first_time_available['day'].' '.$first_time_available['after']);

                    $this->setPickupTime($pickup_time);
                } else {
                    return false;
                }
         
                $pickup_date = new DateTime('@'.$pickup_time);
                $pickup_date->setTimezone($this->getTimeZone());
            }
        
            if ($pickup_date->getTimestamp() !== $pickup_time) {
                $this->setPickupTime($pickup_date);
            }

            if (empty($address_1)) {
                return false;
            }

            $address_str = $address_1.' , '.$postcode.' '.$city;
            
            if (is_a($object, 'WC_Order')) {
                $origin_string = $this->getPickupAddress();
            } else {
                $filter_postcodes = $this->getOption('only_postcodes', $object);

                if (!empty($filter_postcodes)) {
                    $allowed_postcodes = explode(',', $filter_postcodes);

                    if (!empty($allowed_postcodes) && !in_array($postcode, $allowed_postcodes)) {
                        if ($this->getOption('debug_mode') == "yes") {
                            $this->addLog('prepareJobObject::FilteredPostcodes', array('filter_postcodes' => $allowed_postcodes, 'postcode' => $postcode));
                        }

                        return false;
                    }
                }

                $origin_string = $this->getPickupAddress(array('city' => $city, 'address1' => $address_1, 'address2' => $address_2, 'postcode' => $postcode, 'country' => $country));
            }

            if (empty($origin_string)) {
                return false;
            }

            $delivery_mode = 'auto';
            $pickup_comment = $this->getOption('comment', $object);
            $pickup_first_name = $this->getOption('first_name', $object);
            $pickup_last_name = $this->getOption('last_name', $object);
            $pickup_phone = $this->getOption('phone', $object);
            $pickup_email = $this->getOption('email', $object);
            $pickup_company_name = $this->getOption('company_name', $object);

            $pickup_obj = array(0 => array());
            $pickup_obj[0]['address'] = $origin_string;
            $pickup_obj[0]['comment'] =  $pickup_comment;
            $pickup_obj[0]['contact']['firstname'] = $pickup_first_name;
            $pickup_obj[0]['contact']['lastname'] = $pickup_last_name;
            $pickup_obj[0]['contact']['phone'] = $pickup_phone;
            $pickup_obj[0]['contact']['email'] = $pickup_email;
            $pickup_obj[0]['contact']['company'] = $pickup_company_name;

            $pickup_obj = apply_filters('stuart_pickup_infos', $pickup_obj, $object);

            if (empty($pickup_obj) || empty($pickup_obj[0])) {
                return false;
            }

            $address_obj = array();
            $address_obj['address'] = $address_str;
            $address_obj['package_description'] = "Order/Cart #".$object_id;
            $address_obj['comment'] = $address_2;

            $address_obj['contact']['firstname'] = $first_name;
            $address_obj['contact']['lastname'] = $last_name;
            $address_obj['contact']['phone'] = $phone;
            $address_obj['contact']['email'] = $email;
            $address_obj['contact']['company'] = $company_name;

            $params = new stdClass;
            $params->job = new stdClass;
            $params->job->pickup_at = $pickup_date->format('c');
            $params->job->assignment_code = strval($object_id);

            $is_france = strpos(strtolower($pickup_obj[0]['address']), 'franc') !== false || substr($pickup_obj[0]['address'], -2) == 'FR';

            $package_type = $this->getPackagesType($products, $is_france);

            if ($package_type == false) {
                return false;
            }

            if ($delivery_mode !== 'auto') {
                $params->job->transport_type = strval($delivery_mode);
            } else {
                $address_obj['package_type'] = $package_type;
            }

            $pickups = array();

            foreach ($pickup_obj as $key => $value) {
                $pickups[] = (object) $value;
            }

            $params->job->pickups = $pickups;
            $params->job->dropoffs = array((object)$address_obj);

            return apply_filters('stuart_job_object', $params, $object);
        }


        public function getSession($order_id = false)
        {
            $session = WC()->session;

            if (empty($session)) {
                if (!empty($order_id)) {
                    $order = new WC_Order((int) $order_id);
                    $cart_hash = $order->get_cart_hash();
                } else {
                    $cart = $this->getCart();

                    if (isset($cart->session)) {
                        $session = $cart->session;
                    }
                }

                if (isset($cart_hash) && !empty($cart_hash)) {
                    $session = new WC_Cart_Session($cart_hash);
                }
            }

            return $session;
        }

        public function getFirstDeliveryTime($time_list = array())
        {
            if (!empty($time_list)) {
                foreach ($time_list as $key => $value) {
                    return $value;
                    break;
                }
            }

            return false;
        }

        public function getPickupTime($object = false)
        {
            if (is_a($object, 'WC_Order')) {
                $pickup_str = $object->get_meta('stuart_pickup_time', true);
                $pickup_str = apply_filters('stuart_get_pickup_order_time', $pickup_str, $object);
                $time = $this->getTime($pickup_str);

                if ($this->getOption('debug_mode') == "yes") {
                    $this->addLog('getPickupTime::OrderObject', array('order_id' => is_a($object, 'WC_Order') ? $object->get_id() : '', 'pickup_str' => $pickup_str, 'pickup_date' => $this->dateToFormat($time, 'c'), 'is_delivery_time' => $this->isDeliveryTime($time, $object)));
                }
                return $time;
            }

            $session = $this->getSession();

            if (empty($session)) {
                $pickup = false;
            } else {
                $pickup = $session->get('stuart_pickup_time');
            }

            if (!empty($pickup)) {
                if (is_numeric($pickup)) {
                    $pickup_str = "@".$pickup;
                } else {
                    $pickup_str = $pickup;
                }

                $pickup_time = $this->getTime($pickup_str);

                if (!$this->isDeliveryTime($pickup_time, $object)) {
                    unset($pickup_time);
                }
            }

            if (!isset($pickup_time)) {
                $time_list = $this->getDeliveryTimeList($object);

                $first_time_available = $this->getFirstDeliveryTime($time_list);

                if ($first_time_available) {
                    $basic_str = $first_time_available['day'].' '.$first_time_available['after'];
                } else {
                    $basic_str = "";
                }

                if (empty($basic_str)) {
                    return false;
                } else {
                    $pickup_time = $this->getTime($basic_str);
                }
            
                if (!is_a($object, 'WC_Order')) {
                    $this->setPickupTime($pickup_time);
                }
            }

            $date = new DateTime('@'.$pickup_time);
            $date->setTimezone($this->getTimeZone());
            $pickup_time = $date->format('U');

            if ($this->getOption('debug_mode') == "yes") {
                $this->addLog('getPickupTime::pickupTime', $pickup_time);
            }

            return (int) $pickup_time;
        }

        public function dateToFormat($str = 'now', $format = 'c')
        {
            if (is_numeric($str)) {
                $str = '@'.$str;
            }

            $datetime = new DateTime($str);
            $datetime->setTimezone($this->getTimeZone());
            return $datetime->format($format);
        }

        public function formatToDate($format = 'c', $str = 'now')
        {
            return $this->dateToFormat($str, $format);
        }

        public function getTime($str = 'now')
        {
            return (int) $this->dateToFormat($str, 'U');
        }

        public function getSettingTime($date = null, $time = null)
        {
            $obj = new DateTime('now', $this->getTimeZone());

            if (strpos($date, '-') !== false) {
                $date_obj = new DateTime($date);
                $day = $date_obj->format('d');
                $month = $date_obj->format('m');
                $year = $date_obj->format('Y');
             
                $obj->setDate($year, $month, $day);
            }

            if (strpos($time, ":") !== false) {
                if (empty($date)) {
                    $date = 'today';
                }

                $time_string = str_replace(array('.'), '', $date.' '.$time);
                $time_obj = new DateTime($time_string);
                $hour = $time_obj->format('H');
                $minutes = $time_obj->format('i');

                $obj->setTime($hour, $minutes);
            }

            return (int) $obj->format('U');
        }

        public function setPickupTime($pickup_time, $order_id = false)
        {
            if ($this->getOption('debug_mode') == "yes") {
                $this->addLog('setPickupTime::pickup_time', $pickup_time);
            }
            if (!empty($pickup_time)) {
                if (is_a($pickup_time, 'DateTime')) {
                    $pickup_str = $this->getTime($pickup_time->format('Y-m-d H:i:s'));
                } else {
                    if (is_numeric($pickup_time)) {
                        $pickup_str = (int) $pickup_time;
                    } elseif ($this->validateDate($pickup_time)) {
                        $pickup_str = $this->getTime($pickup_time);
                    }
                }
            }

            if (!isset($pickup_str)) {
                if ($order_id) {
                    $order = new WC_Order($order);
                } else {
                    $order = false;
                }

                $pickup_str = $this->getTime('now + '.$this->getDelay($order).' minutes');
            }
                
            $pickup_string_datezoned = $this->dateToFormat($pickup_str, 'c');

            if (empty($order_id)) {
                $session = $this->getSession();

                if (!empty($session)) {
                    $session->set('stuart_pickup_time', $pickup_string_datezoned);
                }
            } else {
                update_post_meta($order_id, 'stuart_pickup_time', $pickup_string_datezoned);
            }
        }

        private function getCustomer()
        {
            if (!function_exists('getInstance')) {
                require_once 'stuart-woocommerce.php';
            }
            $stuart = Stuart::getInstance();
            return $stuart->getCustomer();
        }

        private function getCart()
        {
            if (!function_exists('getInstance')) {
                require_once 'stuart-woocommerce.php';
            }
            $stuart = Stuart::getInstance();
            return $stuart->getCart();
        }

        public function getTimeZone()
        {
            if (function_exists('wp_timezone')) {
                return wp_timezone();
            }

            $timezone_string = get_option('timezone_string');
         
            if ($timezone_string) {
                return new DateTimeZone($timezone_string);
            }

            $offset  = (float) get_option('gmt_offset');
            $hours   = (int) $offset;
            $minutes = ($offset - $hours);
         
            $sign      = ($offset < 0) ? '-' : '+';
            $abs_hour  = abs($hours);
            $abs_mins  = abs($minutes * 60);
            $tz_offset = sprintf('%s%02d:%02d', $sign, $abs_hour, $abs_mins);

            return new DateTimeZone($tz_offset);
        }

        public function getDeliveryTimeList($context = false)
        {
            if (!empty($this->time_list_cache)) {
                return $this->time_list_cache;
            }

            $result = array();
            
            $days_limit = (int) $this->getOption('days_limit', $context) > 0 && (int) $this->getOption('days_limit', $context) < 93 ? (int) $this->getOption('days_limit', $context) : 21 ;
            $first_day = true;
            $days_numbers = array(
              "1" => 'monday',
              "2" => 'tuesday',
              "3" => 'wednesday',
              "4" => 'thursday',
              "5" => 'friday',
              "6" => 'saturday',
              "7" => 'sunday'
            );

            $delay = (int) $this->getOption('delay', $context);

            $one_day_minutes = 1440;

            if ($delay >= $one_day_minutes) {
                $days = $delay / $one_day_minutes;

                $day_delay = floor($days);
            } else {
                $day_delay = 0;
            }

            for ($i = $day_delay; $i < $days_limit; ++$i) {
                $day_time = 'today';
                $day = (string) $this->dateToFormat($this->getTime($day_time.' + '.$i.' day'), 'N');
                $day_name = $days_numbers[$day];

                $addon_minutes = 1;

                $day_low_hour = $this->getOption('lowest_hour_'.$day_name, $context);
                $day_high_hour = $this->getOption('highest_hour_'.$day_name, $context);

                $working_pause_low = $this->getOption('lowest_hour_pause_'.$day_name, $context);
                $working_pause_high = $this->getOption('highest_hour_pause_'.$day_name, $context);

                if (!empty($day_low_hour) && !empty($day_high_hour)) {
                    $high_date = new DateTime('today '.$day_high_hour.' - 1 minutes + '.$i.' day ');
                    $low_date = new DateTime('today '.$day_low_hour.' + '.$addon_minutes.' minutes + '.$i.' day ');

                    $high_time = $this->getSettingTime($high_date->format('d-m-Y'), $high_date->format('H:i'));
                    $low_time = $this->getSettingTime($low_date->format('d-m-Y'), $low_date->format('H:i'));

                    if ($this->isDeliveryTime($high_time, $context) && $this->isDeliveryTime($low_time, $context)) {
                        $result[$i] = array(
                          'day' => 'now + '.$i.' day',
                          'before' => $day_high_hour,
                          'after' => $day_low_hour,
                          'pause_start' => $working_pause_low,
                          'pause_end' => $working_pause_high
                        );

                        $first_day = false;
                    }
                }
            }

            if ($this->getOption('debug_mode') == "yes") {
                $this->addLog('getDeliveryTimeList::timeList', $result);
            }

            $this->time_list_cache = $result;
            
            return apply_filters('stuart_delivery_time_list', $result, $context);
        }

        public function isDeliveryTime($time = 'now', $context = false)
        {
            $pickup_time = $this->getTime($time);

            $day_stuart = $this->dateToFormat($pickup_time, 'd-m-Y');
            $now_day = $this->dateToFormat('now', 'd-m-Y');

            $stuart_hour_low_limit = $this->getSettingTime($day_stuart, '8:30');
            $stuart_hour_high_limit = $this->getSettingTime($day_stuart, '23:30');

            $least_delivery = $this->getTime('now + '.$this->getDelay($context).' minutes');

            if ($pickup_time < $least_delivery) {
                return false;
            }

            if ($pickup_time < $stuart_hour_low_limit || $pickup_time > $stuart_hour_high_limit) {
                return false;
            }

            $days_numbers = array(
              "1" => 'monday',
              "2" => 'tuesday',
              "3" => 'wednesday',
              "4" => 'thursday',
              "5" => 'friday',
              "6" => 'saturday',
              "7" => 'sunday'
            );

            $day = (string) $this->dateToFormat($pickup_time, 'N');
            $day_name = $days_numbers[$day];

            $day_low_hour = $this->getOption('lowest_hour_'.$day_name, $context);
            $day_high_hour = $this->getOption('highest_hour_'.$day_name, $context);

            if (!empty($day_low_hour)) {
                $lowest_conf = $this->getSettingTime($day_stuart, $day_low_hour);
            } else {
                return false;
            }

            if (!empty($day_high_hour)) {
                $highest_conf = $this->getSettingTime($day_stuart, $day_high_hour);
            } else {
                return false;
            }
            
            if ($pickup_time < $lowest_conf || $pickup_time > $highest_conf) {
                return false;
            }

            $working_pause_low = $this->getOption('lowest_hour_pause_'.$day_name, $context);
            $working_pause_high = $this->getOption('highest_hour_pause_'.$day_name, $context);
            
            if (!empty($working_pause_low) && !empty($working_pause_high)) {
                $lowest_pause = $this->getSettingTime($day_stuart, $working_pause_low);
                $highest_pause = $this->getSettingTime($day_stuart, $working_pause_high);

                if ($pickup_time > $lowest_pause && $pickup_time < $highest_pause) {
                    return false;
                }
            }

            $holidays = $this->getOption('holidays', $context);

            if (!empty($holidays)) {
                $explode = explode(',', $holidays);
            }

            if (isset($explode) && !empty($explode)) {
                $holidays_array = $explode;
            } else {
                $holidays_array = array();
            }

            if ($this->getOption('working_'.$day_name, $context) == 'no' || in_array(date('d/m', $pickup_time), $holidays_array)) {
                return false;
            }

            return true;
        }

        private function validateDate($date)
        {
            return (bool) strtotime($date);
        }

        public function createJob($order_id)
        {
            $token = $this->setStuartAuth();
        
            $job_id = $this->getJobId($order_id);

            do_action('stuart_create_job', $order_id);

            if ((int) $job_id > 0) {
                return false;
            }

            $order = new WC_Order($order_id);

            if ((bool) $order->get_meta('has_sub_order') == true) {
                return false;
            }

            $params = $this->prepareJobObject($order);
 
            if (empty($params)) {
                return false;
            }

            if ((int) get_post_meta($order_id, 'stuart_job_creation', true) > 0) {
                return;
            }

            update_post_meta($order_id, 'stuart_job_creation', 1);

            if ($this->getOption('debug_mode') == "yes") {
                $this->addLog('createJob::data', array('params' => $params, 'order_id'=> $order_id));
            }

            $url = '/v2/jobs';

            $response = $this->makeApiRequest($url, $token, $params, true, true);
            
            if (!empty($response) && isset($response['id'])) {
                $pickup = $this->getPickupTime($order);
                
                update_post_meta($order_id, 'stuart_pickup_time', (is_numeric($pickup) ? date('c', $pickup) : $pickup));
                
                update_post_meta($order_id, 'stuart_job_id', $response['id']);
                
                delete_post_meta($order_id, 'stuart_job_creation_error');

                return $response;
            }
            
            update_post_meta($order_id, 'stuart_job_creation_error', $response);

            update_post_meta($order_id, 'stuart_job_creation', 0);

            return false;
        }

        public function rescheduleJob($pickup_time = 'now', $order_id = false, $creation = false)
        {
            if (!is_numeric($pickup_time)) {
                if (is_a($pickup_time, 'DateTime')) {
                    $pickup_time = $pickup_time->getTimestamp();
                } else {
                    $pickup_time = $this->getTime($pickup_time);
                }
            }

            $this->setPickupTime($pickup_time, $order_id);

            if ($creation === true && !empty($order_id)) {
                $job_creation = $this->createJob($order_id);
            }
      
            if ($order_id) {
                $object = new WC_Order($order_id);
            } else {
                $object = $this->getCart();
            }

            $price = $this->getJobPricing($object);

            if ($job_creation !== false) {
                $order = wc_get_order($order_id);
                $note = esc_html__('The delivery was created on Stuart', 'stuart-delivery');
                $order->add_order_note($note);
                return true;
            } else {
                $order = wc_get_order($order_id);
                $note = esc_html__('There was a problem canceling the delivery on Stuart', 'stuart-delivery');
                $order->add_order_note($note);
                return false;
            }

            return $price;
        }

        public function getJobId($order_id)
        {
            if (!empty($order_id)) {
                $order = new WC_Order($order_id);
                
                $meta = $order->get_meta('stuart_job_id', true);

                return apply_filters('stuart_get_job_id', $meta, $order);
            } else {
                return false;
            }
        }

        public function cancelJob($order_id)
        {
            $token = $this->setStuartAuth();
            $job_id = $this->getJobId($order_id);

            if (empty($job_id)) {
                return false;
            }

            $url = "/v2/jobs/".urlencode($job_id)."/cancel";

            $return = $this->makeApiRequest($url, $token, "{}", false, true);

            if ($return !== "") {
                $order = wc_get_order($order_id);
                $note = esc_html__('There was a problem canceling the delivery', 'stuart-delivery');
                $order->add_order_note($note);
                return false;
            } else {
                $order = wc_get_order($order_id);
                $note = esc_html__('The delivery was canceled on Stuart', 'stuart-delivery');
                $order->add_order_note($note);
                update_post_meta($order_id, 'stuart_job_id', '-1');
                return true;
            }
        }

        public function connectedNotice()
        {
            ?>
            <div id="connexion-status" class="update-stuart-delivery">
                
              <p style='color: #27ae60;'><?php esc_html_e('Your website is connected to the Stuart API and running.', 'stuart-delivery'); ?></p>

              <?php

                $state = $this->getOption('licence_status');

            if (empty($state)) {
                $state = "inactive";
            } ?>
            </div>
          <?php
        }

        public function notConnectedNotice($obj = false)
        {
            ?>
            <div id="connexion-status" class="update-stuart-delivery" style='color: red;'>
                <p><?php esc_html_e('Your website cannot connect to the Stuart API. Please verify informations provided.', 'stuart-delivery'); ?></p>
                <?php

                  if (!empty($obj) && isset($obj->error_description)) {
                      echo "<p><b>".$obj->error_description."</b></p>";
                    
                      if (isset($obj->error)) {
                          echo "<p><i>".$obj->error."</i></p>";
                      }
                  } ?>
            </div>
          <?php
        }

        public function checkJobStates()
        {
            global $wpdb;
                    
            $date_from = date('Y-m-d', $this->getTime('now - '.(int) $this->getOption('days_limit').' days'));
            $date_to = date('Y-m-d');

            $result = $wpdb->get_results("
                SELECT * FROM $wpdb->posts 
                WHERE post_type = 'shop_order'
                AND post_status IN ('wc-processing')
                AND post_date BETWEEN '{$date_from}  00:00:00' 
                AND '{$date_to} 23:59:59'
            ", ARRAY_A);

            $orders_changed = array();

            if (!empty($result)) {
                foreach ($result as $entry) {
                    if (isset($entry['ID'])) {
                        $order = new WC_Order((int) $entry['ID']);

                        if ($order->has_shipping_method($this->id)) {
                            $job_id = $this->getJobId($order->get_id());
                            $job = $this->getJob($job_id);

                            if (!empty($job)) {
                                if (isset($job->status) && $job->status == 'finished') {
                                    $order->update_status('completed');
                                    $orders_changed[] = $order->get_id();
                                }
                            }
                        }
                    }
                }
            }

            if ($this->getOption('debug_mode') == "yes" && !empty($orders_changed)) {
                $this->addLog('checkJobStates::ordersChanged', array($orders_changed));
            }
        }

        public function checkAddressInZone($address_str, $type = "picking")
        {
            $token = $this->setStuartAuth();

            if (!empty($token) && !empty($address_str)) {
                $url = '/v2/addresses/validate?address='.urlencode($address_str).'&type='.urlencode($type);

                $obj = $this->makeApiRequest($url, $token, array(), false, true);

                if (isset($obj->success)) {
                    return (bool) $obj->success;
                }
            }

            return false;
        }

        public function getDelay($context = false)
        {
            $delay = 30;

            if ($this->getOption('delay', $context) !== false) {
                $delay = (int) $this->getOption('delay', $context);

                $one_day_minutes = 1440;

                if ($delay >= $one_day_minutes) {
                    $days = $delay / $one_day_minutes;
                }
            }

            return $delay;
        }
    }
}
