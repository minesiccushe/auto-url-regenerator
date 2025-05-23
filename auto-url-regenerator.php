<?php
/*
Plugin Name: Auto URL Regenerator
Description: A plugin that adds a unique identifier to post URLs and automatically updates them periodically.
Version: 1.1.0
Requires PHP: 7.4
Author: Iccushe
Text Domain: autourlregenerator
License: GPLv2
*/

if ( ! defined( 'ABSPATH' ) || ! defined( 'WPINC' ) ) :
	exit;
endif;

// Plugin version.
define( 'AUTO_URL_REGENERATOR_CURRENT_VERSION', '1.1.0' );

/**
 * Load plugin textdomain for internationalization.
 *
 * @since 1.0.0
 * @return void
 */
function aurg_load_textdomain() {
	load_plugin_textdomain( 'autourlregenerator', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'plugins_loaded', 'aurg_load_textdomain' );

if ( !class_exists( 'Auto_URL_Regenerator' ) ) :
	/**
	 * Main class for the Auto URL Regenerator plugin.
	 * Handles option settings, URL generation, redirection, and rewrite rules.
	 */
	class Auto_URL_Regenerator
	{
		private static $default_post_type = array( 'post', 'page', 'attachment' );

		private static $dotw = array();

		private static $interval_week = array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' );

		private static $identifier;

		private static $options;

		const NONCE_ACTION = 'aurg_option_setting';

		/**
		 * Constructor for the Auto_URL_Regenerator class.
		 * Initializes static properties, sets up WordPress hooks for admin functionality,
		 * URL filtering, and redirection if the plugin is enabled.
		 *
		 * @global \WP_Rewrite $wp_rewrite WordPress rewrite object.
		 * @return void
		 */
		public function __construct()
		{
			global $wp_rewrite;

			self::$dotw = array(
				__( 'Sunday', 'autourlregenerator' ),
				__( 'Monday', 'autourlregenerator' ),
				__( 'Tuesday', 'autourlregenerator' ),
				__( 'Wednesday', 'autourlregenerator' ),
				__( 'Thursday', 'autourlregenerator' ),
				__( 'Friday', 'autourlregenerator' ),
				__( 'Saturday', 'autourlregenerator' ),
			);

			register_deactivation_hook( __FILE__, array( $this, 'deactivation_hook' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'aurg_register_scripts') );

			// Ensure options are initialized as arrays to prevent errors if options are not yet saved (e.g., new install)
			// This is crucial for PHP 8+ where array access on null/false can cause fatal errors.
			self::$options = get_option( 'aurg_options' );
			if ( !is_array(self::$options) ) {
				self::$options = array(); // Default to an empty array if 'aurg_options' is not set or not an array.
			}

			self::$identifier = get_option( 'aurg_identifier' );
			// Ensure self::$identifier and its 'hash_values' sub-array exist and are arrays.
			// 'hash_values' stores the generated identifiers for each post type.
			if ( !is_array(self::$identifier) ) {
				self::$identifier = array('hash_values' => array()); // Default structure if 'aurg_identifier' is not set.
			} elseif ( !isset(self::$identifier['hash_values']) || !is_array(self::$identifier['hash_values']) ) {
				self::$identifier['hash_values'] = array(); // Ensure 'hash_values' key exists and is an array.
			}

			if ( is_admin() )
			{
				add_action( 'admin_menu', array( $this, 'admin_menu' ) );
				add_filter( 'plugin_action_links', array( $this, 'plugin_action_links' ), 10, 2 );
			}

			if ($wp_rewrite->permalink_structure == '' || !self::is_include_postname())
			{
				add_action('admin_notices',array( $this, 'admin_notice_aurg_disable'));
			}

			if(self::get_aurg_enable() === '1' && $wp_rewrite->permalink_structure !== '' )
			{
				$this->set_interval();
				add_filter( 'post_link', array( $this, 'get_post_link_to_support' ), 5 ,3);
				add_filter( 'page_link', array( $this, 'get_page_link_to_support' ), 5 ,3);
				add_filter( 'attachment_link', array( $this, 'get_attachment_link_to_support' ), 5 ,2);
				add_filter( 'post_type_link', array( $this, 'get_post_type_link_to_support' ), 5, 4);
				add_action( 'template_redirect', array( $this, 'redirect_correct_url' ) );
				add_action( 'admin_menu', array( $this, 'add_aurg_checkbox' ) );
				add_action( 'save_post', array( $this, 'save_aurg_checkbox_fields' ) );
			}

		}
		
		/**
		 * Destructor for the Auto_URL_Regenerator class.
		 * Currently does nothing.
		 *
		 * @return void
		 */
		public function __destruct()
		{
		}

		/**
		 * Adds the plugin's options page to the WordPress admin menu under "Settings".
		 *
		 * @return void
		 */
		public function admin_menu()
		{
			$options_hook = add_options_page( __( 'Auto URL Regenerator', 'autourlregenerator' ), __( 'Auto URL Regenerator', 'autourlregenerator' ),'manage_options','aurg_options', '__return_NULL' );
			if($options_hook)
			{
				// Hook for loading the options page content and saving settings.
				add_action("load-{$options_hook}", array(__CLASS__,'admin_load_aurg_options' ) );
			}
		}

		/**
		 * Handles the loading of the plugin's admin options page.
		 * This includes enqueueing scripts/styles, displaying content, and processing form submissions.
		 *
		 * @return void
		 */
		public static function admin_load_aurg_options()
		{
			$screen = get_current_screen();
			$hook_suffix = $screen->id; // The hook suffix for the current admin page.
			add_action('admin_enqueue_scripts', array(__CLASS__, 'aurg_enqueue_scripts'));
			add_action($hook_suffix, array(__CLASS__, 'admin_aurg_option_content' ) );

			$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : FALSE;

			if ($action) {
				self::admin_aurg_option_save($action);
			}

			if (did_action($hook_suffix) ) {
				do_action($hook_suffix);
			}
		}

		/**
		 * Saves the plugin settings submitted from the admin options page.
		 * Handles nonce verification, sanitization of input, and updating WordPress options.
		 *
		 * @param string $action The action being performed, typically 'save'.
		 * @return void This function typically exits via wp_redirect after saving.
		 */
		public static function admin_aurg_option_save($action)
		{
			if ($action == 'save' ) {
				
				check_admin_referer(self::NONCE_ACTION); // Verify nonce for security.

				// Update 'aurg_enable' option.
				if(isset($_POST['aurg_enable']) && $_POST['aurg_enable'] === '1' ){
					self::$options['aurg_enable'] = '1';
					$flush = FALSE;
				}else{
					self::$options['aurg_enable'] = '0';
					$flush = TRUE;
				}

				self::$options['aurg_post_type'] = array();
				if(is_array($_POST['aurg_post_type']) ){
					foreach($_POST['aurg_post_type'] as $value){
						self::$options['aurg_post_type'][] = sanitize_text_field($value);
					}
				}

				$post_types = get_post_types( array( 'public' => TRUE), 'object', 'and' );
				$before_interval = self::get_aurg_interval(); // Relies on self::$options being an array (guaranteed by constructor)

				foreach ($post_types as $value) { // $value is WP_Post_Type object
					// Provide defaults for POSTed interval values before sanitization.
					// This ensures that even if form data is missing, we have valid fallback values.
					$interval_kind_posted = isset($_POST[$value->name.'_interval_kind']) ? $_POST[$value->name.'_interval_kind'] : '0'; // Default 'Daily'
					$interval_hour_posted = isset($_POST[$value->name.'_interval_hour']) ? $_POST[$value->name.'_interval_hour'] : '0'; // Default '0 o\'clock'
					$interval_week_posted = isset($_POST[$value->name.'_interval_week']) ? $_POST[$value->name.'_interval_week'] : '0'; // Default 'Sunday'
					$interval_day_posted  = isset($_POST[$value->name.'_interval_day'])  ? $_POST[$value->name.'_interval_day']  : '1'; // Default '1st day'

					$interval_kind = sanitize_text_field( $interval_kind_posted );
					$interval_hour = sanitize_text_field( $interval_hour_posted );
					$interval_week = sanitize_text_field( $interval_week_posted );
					$interval_day  = sanitize_text_field( $interval_day_posted );

					$current_salt = '';
					// Safely retrieve the current salt for this post type, if it exists from a previous save.
					// This prevents errors if $before_interval or specific keys are not set.
					if (is_array($before_interval) && isset($before_interval[$value->name]) && is_array($before_interval[$value->name]) && isset($before_interval[$value->name]['interval_salt'])) {
						$current_salt = $before_interval[$value->name]['interval_salt'];
					}

					// Safely retrieve the old interval_kind value.
					// This is used as the HMAC key for salt generation if interval_kind is '3' (Once Only).
					// While using a dynamic value like the previous interval_kind as an HMAC key is unusual and not cryptographically strong,
					// this preserves the original logic's intent of changing the salt if the "Once Only" option is re-selected after being something else.
					$hmac_key_for_salt = '';
					if (is_array($before_interval) && isset($before_interval[$value->name]) && is_array($before_interval[$value->name]) && isset($before_interval[$value->name]['interval_kind'])) {
						$hmac_key_for_salt = (string) $before_interval[$value->name]['interval_kind'];
					}
					
					self::$options['aurg_interval'][$value->name]= array(
						'interval_kind' => $interval_kind, // 0:Daily, 1:Weekly, 2:Monthly, 3:Once
						'interval_hour' => $interval_hour, // 0-23
						'interval_week' => $interval_week, // 0-6 (Sun-Sat)
						'interval_day'  => $interval_day,  // 1-28, or 99 for end of month
						// Generate new salt if 'Once Only' is selected; otherwise, keep the existing salt.
						'interval_salt' => ($interval_kind === '3' ) ? hash_hmac( 'sha256', mt_rand(), $hmac_key_for_salt) : $current_salt,
					);
				}

				update_option( 'aurg_options',self::$options,FALSE);
				$redirect_uri = add_query_arg( 'setting', 'saved' );
				self::add_rewrite_rules($flush);
				wp_redirect($redirect_uri);
				exit(); // Redirect to prevent form resubmission.
			}
		}

		/**
		 * Displays the HTML content for the plugin's admin options page.
		 * Includes tabs for basic and interval settings, and form fields for all options.
		 *
		 * @return void
		 */
		public static function admin_aurg_option_content()
		{
			$message = NULL; // Message to display after saving settings.
			if(isset($_GET['setting']) ){
				switch ($_GET['setting']) {
					case 'saved':
						$message = __( 'Settings saved.', 'autourlregenerator' );
						break;
				}
			}
			$post_type_list = self::get_post_type_list();
			$aurg_enable    = self::get_aurg_enable();
			$aurg_post_type = self::get_aurg_post_type();
			$aurg_interval  = self::get_aurg_interval();

			?>
			<?php if(isset($_GET['setting']) ):?>
			<div id="message" class="notice notice-success is-dismissible">
				<p><?php echo esc_html($message);?></p>
			</div>
			<?php endif;?>
			<h1><?php _e( 'Auto URL Regenerator', 'autourlregenerator' ); ?></h1>
			<form method="post" id="aurg_options">
				<?php wp_nonce_field(self::NONCE_ACTION);?>
				<input type="hidden" name="action" value="save">
				<h2 class="nav-tab-wrapper">
					<a href="#tab1" class="nav-tab nav-tab-active"><?php _e( 'Basic Settings', 'autourlregenerator' ); ?></a>
					<a href="#tab2" class="nav-tab"><?php _e( 'Interval Settings', 'autourlregenerator' ); ?></a>
				</h2>
				<div class="tab_container">
					<div class="tab_contents tab_contents-active" id="tab1">
						<table class="form-table">
							<tbody>
								<tr>
									<th><?php _e( 'Enable Automatic Update', 'autourlregenerator' ); ?></th>
									<td>
										<select id="aurg_enable" name="aurg_enable"> 
											<option <?php selected($aurg_enable,'0' ); ?> value="0"><?php _e( 'Disable', 'autourlregenerator' ); ?></option>
											<option <?php selected($aurg_enable,'1' ); ?> value="1"><?php _e( 'Enable', 'autourlregenerator' ); ?></option>
										</select>
									</td>
								</tr>
								<tr>
									<th><?php _e( 'Post Types to Update', 'autourlregenerator' ); ?></th>
									<td>
										<?php if(!empty($post_type_list['default']) ):?>
											<h3><?php _e( 'Default Post Types', 'autourlregenerator' ); ?></h3>
											<?php foreach($post_type_list['default'] as $value):?>
												<label><input type="checkbox" name="aurg_post_type[]" value="<?php echo esc_attr($value->name);?>" <?php checked(in_array($value->name,$aurg_post_type),true);?>><?php echo esc_html($value->label);?></label><br>
											<?php endforeach;?>
										<?php endif;?>
											<?php if(!empty($post_type_list['custom']) ):?>
											<h3><?php _e( 'Custom Post Types', 'autourlregenerator' ); ?></h3>
											<?php foreach($post_type_list['custom'] as $value):?>
												<label><input type="checkbox" name="aurg_post_type[]" value="<?php echo esc_attr($value->name);?>" <?php checked(in_array($value->name,$aurg_post_type),true );?>><?php echo esc_html($value->label);?></label><br>
											<?php endforeach;?>
										<?php endif;?>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
					<div class="tab_contents" id="tab2">
						<?php
							$default_types = isset($post_type_list['default']) && is_array($post_type_list['default']) ? $post_type_list['default'] : array();
							$custom_types = isset($post_type_list['custom']) && is_array($post_type_list['custom']) ? $post_type_list['custom'] : array();
							$post_types = array_merge($default_types, $custom_types);
						?>
						<?php foreach($post_types as $value):?>
						<h3><?php echo esc_html($value->label);?></h3>
						<table class="form-table">
						<tbody class="table_interval">
							<tr class="row_interval row_interval_kind">
								<th><?php _e( 'Update Frequency', 'autourlregenerator' ); ?></th>
								<td>
									<select id="<?php echo esc_attr($value->name.'_interval_kind')?>" name="<?php echo esc_attr($value->name.'_interval_kind');?>">
										<?php $selected = (empty($aurg_interval[$value->name]) )?'':$aurg_interval[$value->name]['interval_kind'];?>
										<option <?php selected($selected,0);?> value="0"><?php _e( 'Daily', 'autourlregenerator' ); ?></option>
										<option <?php selected($selected,1);?> value="1"><?php _e( 'Weekly', 'autourlregenerator' ); ?></option>
										<option <?php selected($selected,2);?> value="2"><?php _e( 'Monthly', 'autourlregenerator' ); ?></option>
										<option <?php selected($selected,3);?> value="3"><?php _e( 'Once Only', 'autourlregenerator' ); ?></option>
									</select>
								</td>
							</tr>
							<tr class="row_interval row_interval_hour">
								<th><?php _e( 'Update Time', 'autourlregenerator' ); ?></th>
								<td>
									<select id="<?php echo esc_attr($value->name.'_interval_hour');?>" name="<?php echo esc_attr($value->name.'_interval_hour');?>">
									<?php for($n=0;$n<=23;$n++):?>
										<?php $selected = (empty($aurg_interval[$value->name]) )?'':$aurg_interval[$value->name]['interval_hour'];?>
										<option <?php selected($selected,$n);?> value="<?php echo esc_attr($n);?>"><?php echo esc_html(sprintf(__( '%s o\'clock', 'autourlregenerator' ), $n));?></option>
									<?php endfor;?>
									</select>
								</td>
							</tr>
							<tr class="row_interval row_interval_week">
								<th><?php _e( 'Update Day of Week', 'autourlregenerator' ); ?></th>
								<td>
									<select id="<?php echo esc_attr($value->name.'_interval_week');?>" name="<?php echo esc_attr($value->name.'_interval_week');?>">
										<?php $dotw = self::$dotw?>
										<?php for($n=0;$n<=6;$n++):?>
											<?php $selected = (empty($aurg_interval[$value->name]) )?'':$aurg_interval[$value->name]['interval_week'];?>
											<option <?php selected($selected,$n);?> value="<?php echo esc_attr($n);?>"><?php echo esc_html($dotw[$n]);?></option>
										<?php endfor;?>
									</select>
								</td>
							</tr>
							<tr class="row_interval row_interval_day">
								<th><?php _e( 'Update Day of Month', 'autourlregenerator' ); ?></th>
								<td>
									<select id="<?php echo esc_attr($value->name.'_interval_day');?>" name="<?php echo esc_attr($value->name.'_interval_day');?>">
									<?php for($n=1;$n<=28;$n++):?>
										<?php $selected = (empty($aurg_interval[$value->name]) )?'':$aurg_interval[$value->name]['interval_day'];?>
										<option <?php selected($selected,$n);?> value="<?php echo esc_attr($n);?>"><?php echo esc_html(sprintf(__( '%sth', 'autourlregenerator' ), $n));?></option>
									<?php endfor;?>
									<option <?php selected($selected,99);?> value="99"><?php _e( 'End of month', 'autourlregenerator' ); ?></option>
									</select>
								</td>
							</tr>
						</tbody>
						</table>
						<?php endforeach;?>
					</div>
				</div>
				<p class="submit">
					<?php submit_button( __( 'Save Settings', 'autourlregenerator' ), 'primary', NULL, FALSE); ?>
					<span class="spinner"></span>
				</p>
			</form>
			<?php
		}
		
		/**
		 * Registers the admin-specific CSS and JavaScript files for the plugin.
		 *
		 * @return void
		 */
		public function aurg_register_scripts()
		{
			wp_register_style( 'aurg_style', plugins_url( '', __FILE__ ) . '/admin.css', array(), AUTO_URL_REGENERATOR_CURRENT_VERSION );
			wp_register_script( 'aurg_script', plugins_url( '', __FILE__ ) . '/admin.js', array('jquery'), AUTO_URL_REGENERATOR_CURRENT_VERSION, true );
		}

		/**
		 * Enqueues the registered admin CSS and JavaScript files.
		 * This is typically called on the plugin's options page.
		 *
		 * @return void
		 */
		public static function aurg_enqueue_scripts()
		{
			wp_enqueue_style( 'aurg_style' );
			wp_enqueue_script( 'aurg_script' );
		}
		
		/**
		 * Adds a "Settings" link to the plugin's entry on the WordPress plugins page.
		 *
		 * @param array  $links An array of existing action links for the plugin.
		 * @param string $file  The plugin file name.
		 * @return array Modified array of action links with the new "Settings" link.
		 */
		public function plugin_action_links( $links, $file )
		{
			if ( $file == 'auto-url-regenerator/' . basename(__FILE__) ) {
				$settings_link = '<a href="options-general.php?page=aurg_options">' . __( 'Settings', 'autourlregenerator' ) . '</a>';
				array_unshift( $links, $settings_link ); // Add to the beginning of the links array.
			}
			return $links;
		}

		/**
		 * Displays an admin notice if the permalink structure is not set to include %postname%.
		 * The plugin relies on %postname% for its functionality.
		 *
		 * @return void
		 */
		public function admin_notice_aurg_disable()
		{
			$screen = get_current_screen();
			// Display a specific notice on the permalinks settings page.
			if($screen->id == 'options-permalink'):
			?>
			<div class="notice notice-warning is-dismissible">
				<p><?php 
					echo wp_kses_post( 
						sprintf( 
							__( '【Auto URL Regenerator】Permalinks are not set correctly. The plugin may not work properly. Please include %s in your common settings.', 'autourlregenerator' ), 
							'<strong>%postname%</strong>' 
						) 
					); 
				?></p>
			</div>
			<?php else: ?>
			<div class="notice notice-warning is-dismissible">
				<p><?php 
					echo wp_kses_post( 
						sprintf( 
							__( '【Auto URL Regenerator】Permalinks are not set correctly. The plugin may not work properly. Please review your <a href="%s">permalink settings</a>.', 'autourlregenerator' ), 
							admin_url( 'options-permalink.php' ) 
						) 
					); 
				?></p>
			</div>
			<?php endif;

		}

		/**
		 * Calculates and stores the base hash identifiers for each enabled post type.
		 * The calculation is based on the configured interval settings (daily, weekly, monthly, once).
		 * These base identifiers are stored in the 'aurg_identifier' WordPress option.
		 *
		 * @return void
		 */
		private function set_interval()
		{
			$aurg_post_type = self::get_aurg_post_type(); // Get post types selected for regeneration.

			foreach($aurg_post_type as $value){
				$aurg_interval = self::get_aurg_interval($value);
				$timezone = get_option( 'timezone_string' );
				$date = new DateTime();
				$date->setTimezone(new DateTimeZone($timezone) );
				switch($aurg_interval['interval_kind']){
					case 0:
						$date->modify($aurg_interval['interval_hour'].'hour ago' );
						$date->modify( 'today' );
						self::$identifier['hash_values'][$value] = hash_hmac( 'sha256', $date->format(DateTime::ATOM), $value);
						break;
					case 1:
						$date->modify( 'tomorrow' );
						$date->modify( 'last '.self::$interval_week[$aurg_interval['interval_week']]);
						self::$identifier['hash_values'][$value] = hash_hmac( 'sha256', $date->format(DateTime::ATOM), $value);
						break;
					case 2:
						// Monthly interval calculation revised:
						// The $date variable holds the current datetime, adjusted by WordPress timezone.
						// $reference_date_for_hash will be the actual date used for hash generation (set to 00:00:00 of the target day).
						$reference_date_for_hash = new DateTime('now', new DateTimeZone($timezone));
						$reference_date_for_hash->setTime(0,0,0); // Start with today at midnight as a base.

						if ($aurg_interval['interval_day'] < 29) { // Case for a specific day of the month (1-28, as per UI).
							$day_to_check = (int)$aurg_interval['interval_day'];
							
							// Initially, set reference_date_for_hash to the target day of the *current* month.
							$reference_date_for_hash->setDate((int)$reference_date_for_hash->format('Y'), (int)$reference_date_for_hash->format('m'), $day_to_check);

							// $target_trigger_timestamp is the precise moment (interval_hour on the target day) when the URL should change.
							$target_trigger_timestamp = clone $reference_date_for_hash; // Cloned from target day at 00:00:00.
							$target_trigger_timestamp->setTime((int)$aurg_interval['interval_hour'], 0, 0); // Set to the configured hour.

							// If the current time ($date) is *before* this month's trigger point,
							// the hash should be based on *last month's* target day.
							if ($date < $target_trigger_timestamp) {
								$reference_date_for_hash->modify('first day of last month'); // Go to the 1st of last month.
								// Then set to the correct day_to_check for that previous month.
								$reference_date_for_hash->setDate((int)$reference_date_for_hash->format('Y'), (int)$reference_date_for_hash->format('m'), $day_to_check);
								$reference_date_for_hash->setTime(0,0,0); // Ensure it's at midnight.
							}
							// Otherwise, $reference_date_for_hash (current month's target day at 00:00:00) is correct.

						} else { // Case for "End of month" (where interval_day is 99).
							// Initially, set reference_date_for_hash to the end of the *current* month.
							$reference_date_for_hash->modify('last day of this month');
							$reference_date_for_hash->setTime(0,0,0); // Ensure it's at midnight.

							$target_trigger_timestamp = clone $reference_date_for_hash; // Cloned from EOM at 00:00:00.
							$target_trigger_timestamp->setTime((int)$aurg_interval['interval_hour'], 0, 0); // Set to the configured hour.

							// If the current time ($date) is *before* this month's EOM trigger point,
							// the hash should be based on *last month's* EOM.
							if ($date < $target_trigger_timestamp) {
								$reference_date_for_hash->modify('first day of last month'); // Go to 1st of prior month.
								$reference_date_for_hash->modify('last day of this month');   // Then to end of that prior month.
								$reference_date_for_hash->setTime(0,0,0); // Ensure it's at midnight.
							}
							// Otherwise, $reference_date_for_hash (current month's EOM at 00:00:00) is correct.
						}
						self::$identifier['hash_values'][$value] = hash_hmac( 'sha256', $reference_date_for_hash->format(DateTime::ATOM), $value);
						break;
					case 3: // "Once Only" interval
						self::$identifier['hash_values'][$value] = hash_hmac( 'sha256',$aurg_interval['interval_salt'], $value);
						break;
				}
			}
			update_option( 'aurg_identifier', self::$identifier, FALSE);
		}
		
		/**
		 * Adds custom rewrite rules to WordPress for handling URLs with the generated identifier.
		 * These rules ensure that URLs containing the identifier correctly map to their original posts.
		 *
		 * @param bool $flush Optional. Whether to force flushing rewrite rules. Default false.
		 *                    True is used on deactivation or when permalink settings might have changed.
		 * @global \WP_Rewrite $wp_rewrite WordPress rewrite object.
		 * @return void
		 */
		public static function add_rewrite_rules( $flush = FALSE )
		{
			// If flushing is forced or permalinks don't include %postname%, flush and exit.
			if($flush === TRUE || !self::is_include_postname()){
				flush_rewrite_rules();
				return;
			}

			global $wp_rewrite;
			$post_type_list = self::get_post_type_list(); // All public post types.
			$aurg_post_type = self::get_aurg_post_type(); // Post types enabled for URL regeneration.

			// Add rewrite rules for custom post types.
			foreach ($post_type_list['custom'] as $value) { // $value is a WP_Post_Type object.
				if (in_array($value->name, $aurg_post_type)) {
					// Regex matches: CPT_slug/post-slug-identifier/
					// Target: index.php?post_type=CPT_slug&CPT_slug=post-slug
					add_rewrite_rule($value->name.'/(.+)-[0-9a-f]{8}/?$', 'index.php?post_type=' . $value->name . '&' . $value->name . '=$matches[1]', 'top');
				}
			}

			// Add rewrite rules for default post types (post, page, attachment).
			foreach ($post_type_list['default'] as $value) { // $value is a WP_Post_Type object.
				if (in_array($value->name, $aurg_post_type)) {
					switch ($value->name) {
						case 'post':
							// For posts, the regex is dynamically built based on the site's permalink structure.
							$target_for_rule = ''; // Initialize target for the rule (e.g., 'index.php?name=...')
							$query = array();    // Initialize query array for rule components.
							$regex = ltrim($wp_rewrite->permalink_structure, '/'); // Start with the permalink structure.
							$n = 0; // Counter for $matches in regex.
							
							// Iterate through WordPress rewrite codes (e.g., %year%, %postname%).
							foreach ($wp_rewrite->rewritecode as $code_key => $code_tag) {
								if ($code_tag === '%postname%') {
									// Append identifier pattern to the postname part of the regex.
									$regex = str_replace($code_tag, $wp_rewrite->rewritereplace[$code_key].'-[0-9a-f]{8}', $regex);
									$n++;
									$query[$n] = 'name=$matches['.$n.']'; // Standard query var for post name.
								} else {
									// Replace other tags with their regex equivalents.
									$regex = str_replace($code_tag, $wp_rewrite->rewritereplace[$code_key], $regex, $cnt);
									if ($cnt >= 1) {
										$n++;
										$query_var = str_replace('%', '', $code_tag);
										// Map common permalink tags to their corresponding query variables.
										if ($query_var === 'category') $query_var = 'category_name';
										if ($query_var === 'tag') $query_var = 'tag';
										// Other specific tags might need more explicit mapping if used.
										$query[$n] = $query_var.'=$matches['.$n.']';
									}
								}
							}
							$regex = rtrim($regex, '/').'(?:/([0-9]+) )?/?$'; // Append optional pagination (e.g., /page/2/).
							$n++;
							$query[$n] = 'page=$matches['.$n.']'; // Query var for paged content on single posts.
							
							// Construct the final redirect target for the rule.
							$target_for_rule = 'index.php?'.implode('&', $query);
							add_rewrite_rule($regex, $target_for_rule, 'top');
							break;
						case 'page':
						case 'attachment':
							// Rule for pages and attachments. Example URL: page-slug-pAbcdef12
							// The '-p' prefix is specific to pages/attachments in this plugin.
							add_rewrite_rule('(.+)-p[0-9a-f]{8}/?$', 'index.php?pagename=$matches[1]', 'top');
							break;
					}
				}
			}
			flush_rewrite_rules(); // Flush rules to apply changes.
		}

		/**
		 * Filters the post permalink to add the identifier if regeneration is enabled for the post.
		 * Hooked to 'post_link'.
		 *
		 * @param string   $permalink The original permalink.
		 * @param \WP_Post $post      The post object.
		 * @param bool     $leavename Whether to keep the post name. (Not directly used by this method but part of filter).
		 * @return string The modified permalink with identifier, or original if not applicable.
		 */
		public function get_post_link_to_support($permalink, \WP_Post $post, $leavename)
		{
			$aurg_post_type = self::get_aurg_post_type();
			// Check if 'post' type is enabled, post is valid, permalinks are correct, and checkbox is ON for the post.
			if (in_array( 'post',$aurg_post_type) && self::is_not_incomplete_post_type( $post ) && self::is_include_postname() && self::is_aurg_checkbox( $post ) ){
				$identifier = $this->get_identifier( $post );
				$permalink = str_replace($post->post_name,$post->post_name.'-'.$identifier,$permalink);
			}
			return $permalink;
		}

		/**
		 * Filters the page permalink to add the '-p' prefixed identifier if regeneration is enabled.
		 * Hooked to 'page_link'.
		 *
		 * @param string $link    The original page link.
		 * @param int    $post_id The ID of the page.
		 * @param bool   $sample  Whether this is a sample permalink. (Not directly used but part of filter).
		 * @return string The modified page link with identifier, or original if not applicable.
		 */
		public function get_page_link_to_support($link, $post_id, $sample)
		{
			$post = get_post($post_id);
			$aurg_post_type = self::get_aurg_post_type();
			// Check if 'page' type is enabled, post is valid, permalinks are correct, and checkbox is ON for the page.
			if (in_array( 'page',$aurg_post_type) && self::is_not_incomplete_post_type( $post ) && self::is_include_postname() && self::is_aurg_checkbox( $post ) ){
				$identifier = $this->get_identifier( $post );
				// Pages get a '-p' prefix before the identifier.
				$link = str_replace($post->post_name,$post->post_name.'-p'.$identifier,$link);
			}
			return $link;
		}

		/**
		 * Filters the attachment permalink to add the '-p' prefixed identifier if regeneration is enabled.
		 * Hooked to 'attachment_link'.
		 *
		 * @param string $link    The original attachment link.
		 * @param int    $post_id The ID of the attachment post.
		 * @return string The modified attachment link with identifier, or original if not applicable.
		 */
		public function get_attachment_link_to_support($link, $post_id)
		{
			$post = get_post($post_id);
			$aurg_post_type = self::get_aurg_post_type();
			// Check if 'attachment' type is enabled, permalinks are correct, and checkbox is ON for the attachment.
			// Note: is_not_incomplete_post_type might not be as relevant for attachments but kept for consistency.
			if (in_array( 'attachment',$aurg_post_type) && self::is_include_postname() && self::is_aurg_checkbox( $post ) ){	
				$identifier = $this->get_identifier( $post );
				// Attachments also get a '-p' prefix before the identifier, similar to pages.
				$link = str_replace($post->post_name,$post->post_name.'-p'.$identifier,$link);
			}
			return $link;
		}

		/**
		 * Filters the permalink for custom post types to add the identifier if regeneration is enabled.
		 * Hooked to 'post_type_link'.
		 *
		 * @param string   $post_link The original permalink for the post type.
		 * @param \WP_Post $post      The post object.
		 * @param bool     $leavename Whether to keep the post name. (Not directly used).
		 * @param bool     $sample    Whether this is a sample permalink. (Not directly used).
		 * @return string The modified permalink with identifier, or original if not applicable.
		 */
		public function get_post_type_link_to_support($post_link, \WP_Post $post, $leavename, $sample)
		{
			$aurg_post_type = self::get_aurg_post_type();
			// Check if the specific post type is enabled, post is valid, permalinks are correct, and checkbox is ON.
			if (in_array($post->post_type,$aurg_post_type) && self::is_not_incomplete_post_type( $post ) && self::is_include_postname() && self::is_aurg_checkbox( $post ) ){
				$identifier = $this->get_identifier( $post );
				$post_link = str_replace($post->post_name,$post->post_name.'-'.$identifier,$post_link);
			}
			return $post_link;
		}

		/**
		 * Generates the 8-character unique identifier for a given post.
		 * This identifier is derived from a hash of the post's name (slug) using a
		 * base scheduled hash (specific to the post type and current interval) as the key.
		 *
		 * @param \WP_Post|null $post Optional. The post object. Defaults to the global $post if null.
		 * @return string The 8-character identifier, or an empty string if the base identifier is missing.
		 */
		private function get_identifier($post = NULL)
		{
			if($post == NULL){
				global $post;
			}
			$aurg_identifier = self::get_aurg_identifier($post->post_type); // This is the scheduled hash (daily, weekly, etc.)

			if ( $aurg_identifier === FALSE ) {
				// If the base scheduled identifier for the post type is missing (e.g., options not saved yet, or post type not configured),
				// return an empty string. This prevents hash_hmac from using FALSE (which casts to an empty string) as a key.
				// An empty identifier means the URL won't have the hashed part, e.g., 'post-slug-' or 'post-slug-p'.
				return '';
			}

			// The final 8-char identifier is a hash of the post's name, keyed by the scheduled hash ($aurg_identifier).
			// This makes the identifier unique per post and per regeneration interval.
			// Using substr is slightly more direct for hex strings than mb_substr.
			// The final 8-char identifier is a hash of the post's name, keyed by the scheduled hash ($aurg_identifier).
			// This makes the identifier unique per post and per regeneration interval.
			// Using substr is slightly more direct for hex strings than mb_substr.
			return substr(hash_hmac( 'sha256', $post->post_name, $aurg_identifier), 0, 8);
		}

		/**
		 * Adds a meta box to the post editing screen for each enabled post type.
		 * This meta box contains the on/off switch for URL regeneration for individual posts.
		 *
		 * @return void
		 */
		public function add_aurg_checkbox()
		{
			$aurg_post_type = self::get_aurg_post_type(); // Get post types for which regeneration is enabled.
			foreach ($aurg_post_type as $value) { // $value is the post type slug.
				add_meta_box( 
					'aurg_checkbox', // Meta box ID.
					__( 'URL Automatic Update Setting', 'autourlregenerator' ), // Meta box title.
					array($this, 'insert_aurg_checkbox_field' ), // Callback function to display content.
					$value, // Post type.
					'normal' // Context (where on the screen).
				);
			}
		}

		/**
		 * Displays the content of the URL regeneration meta box.
		 * This includes radio buttons for enabling or disabling regeneration for the current post.
		 *
		 * @global \WP_Post $post The current post object.
		 * @return void
		 */
		public function insert_aurg_checkbox_field()
		{
			global $post;
			// is_aurg_checkbox returns true if regeneration is ON (meta value is '0' or empty).
			$checked = (self::is_aurg_checkbox( $post ) ) ? TRUE : FALSE; 
			?>
				
			<input type="radio" name="aurg_checkbox" value="0"<?php echo esc_attr(($checked) ? ' checked="checked"' : '');?>><?php _e( 'On', 'autourlregenerator' ); ?>
			<input type="radio" name="aurg_checkbox" value="0"<?php echo esc_attr(($checked) ? ' checked="checked"' : '');?>><?php _e( 'On', 'autourlregenerator' ); ?>
			<input type="radio" name="aurg_checkbox" value="1"<?php echo esc_attr(($checked) ? '' : ' checked="checked"');?>> <?php _e( 'Off', 'autourlregenerator' ); ?>
			<?php
		}

		/**
		 * Saves the value of the URL regeneration checkbox when a post is saved.
		 * The value ('0' for On, '1' for Off) is stored in post meta.
		 *
		 * @param int $post_id The ID of the post being saved.
		 * @return void
		 */
		public function save_aurg_checkbox_fields( $post_id )
		{
			// Check if our checkbox data is set in the POST request.
			if(isset($_POST['aurg_checkbox']) ){
				// Sanitize and update the post meta.
				update_post_meta($post_id, 'aurg_checkbox', sanitize_text_field($_POST['aurg_checkbox'] ) );
			}
		}

		/**
		 * Redirects incoming requests to the correct URL structure (with or without identifier)
		 * based on plugin settings and individual post settings.
		 * Hooked to 'template_redirect'.
		 *
		 * @global \WP_Post $post The current post object.
		 * @return void This function may exit via wp_redirect.
		 */
		public function redirect_correct_url()
		{
			global $post;
			$aurg_post_type = self::get_aurg_post_type(); // Get post types enabled for regeneration.
			if( is_singular() && in_array( $post->post_type, $aurg_post_type ) && self::is_not_incomplete_post_type( $post ) && self::is_include_postname() ){
				$http = is_ssl() ? 'https://' : 'http://';
				$url = $http . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"];
				$current_url_parsed = parse_url($url);
				$current_path = $current_url_parsed['path']; // The path part of the currently requested URL.

				if(self::is_aurg_checkbox( $post ) ){ // URL regeneration is ON for this specific post (via post meta).
					$identifier = $this->get_identifier( $post ); // Expected 8-char identifier for this post.
					
					// Only attempt to enforce identifier presence if a valid (non-empty) identifier is expected.
					// If $identifier is empty (e.g., due to missing base aurg_identifier for the post type),
					// we cannot enforce its presence, as this would lead to redirect loops.
					if ( !empty($identifier) ) {
						// If the current URL path does not contain the expected identifier.
						if(strpos( $current_path, $identifier ) === false ){ 
							// Generate the correct URL (which will include the identifier via hooked filters).
							$target_url = self::get_redirect_correct_url( $post ); 
							wp_redirect( $target_url, 301 );
							exit;
						}
					}
				}else{ // URL regeneration is OFF for this specific post.
					// If regeneration is off, but the URL contains an old identifier pattern,
					// redirect to the canonical URL (without the identifier).
					// The regex matches '-<8_hex_chars>' or '-p<8_hex_chars>'.
					if(preg_match("/\/(.+)-p?[0-9a-f]{8}/", $current_path) ){
						// Generate the correct URL (which will NOT include the identifier).
						$target_url = self::get_redirect_correct_url( $post ); 
						wp_redirect( $target_url, 301 );
						exit;
					}
				}
			}
			return; // No redirection needed.
		}

		/**
		 * Retrieves a list of public post types, categorized into 'default' and 'custom'.
		 * Default post types are 'post', 'page', and 'attachment'.
		 *
		 * @return array An array with two keys: 'default' and 'custom', each containing a list of WP_Post_Type objects.
		 */
		private static function get_post_type_list()
		{
			$post_types = get_post_types( array( 'public' => TRUE), 'object', 'and' ); // Get all public post types.
			$default_post_type = self::$default_post_type; // Defined in the class.
			foreach($post_types as $value){
				if(in_array($value->name,$default_post_type) ){
					$post_type_list['default'][] = $value;
				} else {
					$post_type_list['custom'][] = $value;
				}
			}
			return $post_type_list;
		}

		/**
		 * Checks if the URL regeneration feature is globally enabled in plugin settings.
		 *
		 * @return string '1' if enabled, '0' if disabled.
		 */
		private static function get_aurg_enable()
		{
			// self::$options is guaranteed to be an array by the constructor.
			return ( isset(self::$options['aurg_enable']) && self::$options['aurg_enable'] == '1' ) ? '1' : '0';
		}

		/**
		 * Retrieves the list of post types for which URL regeneration is enabled.
		 *
		 * @return array An array of post type slugs. Returns an empty array if none are set.
		 */
		private static function get_aurg_post_type()
		{
			// self::$options is guaranteed to be an array by the constructor.
			return ( isset(self::$options['aurg_post_type']) && is_array(self::$options['aurg_post_type']) ) ? self::$options['aurg_post_type'] : array();
		}

		/**
		 * Retrieves the interval settings for URL regeneration.
		 * If a specific post type is provided, returns settings for that type.
		 * Otherwise, returns all interval settings.
		 *
		 * @param string|null $post_type Optional. The slug of the post type. Default null.
		 * @return array|false An array of interval settings for the specified post type or all types.
		 *                     Returns false if settings for a specific post type are not found.
		 *                     Returns an empty array if no interval settings are set at all (when $post_type is null).
		 */
		private static function get_aurg_interval( $post_type = NULL )
		{
			// self::$options is guaranteed to be an array by the constructor.
			if(!isset( $post_type ) ){
				// Return all interval settings or an empty array if none exist.
				return ( isset(self::$options['aurg_interval']) && is_array(self::$options['aurg_interval']) ) ? self::$options['aurg_interval'] : array();
			}
			
			// Get all interval settings.
			$all_intervals = self::get_aurg_interval(); 
			// Loop through and find settings for the specific post type.
			foreach($all_intervals as $key => $value){
				if($post_type === $key){
					return $value; // Return settings for the specified post type.
				}
			}
			return FALSE; // Settings for the specific post type not found.
		}

		/**
		 * Retrieves the base hash identifier for a specific post type.
		 * These identifiers are generated by set_interval().
		 *
		 * @param string $post_type The post type slug.
		 * @return string|false The stored base hash value for the post type, or false if not found.
		 */
		private static function get_aurg_identifier( $post_type )
		{
			// self::$identifier and self::$identifier['hash_values'] are guaranteed to be arrays by the constructor.
			$identifier_map = self::$identifier['hash_values'];
			foreach($identifier_map as $key => $value){
				if($post_type === $key){
					return $value; // Return the hash for the specified post type.
				}
			}
			return FALSE; // Hash for the specific post type not found.
		}

		/**
		 * Gets the canonical URL for a given post.
		 * This is used as the target URL for redirections. The actual URL generated
		 * will be affected by the plugin's own permalink filters, ensuring the
		 * identifier is added or removed based on current settings.
		 *
		 * @param \WP_Post|null $post Optional. The post object. Defaults to the global $post if null.
		 * @return string The permalink for the post.
		 */
		private static function get_redirect_correct_url( \WP_Post $post = NULL )
		{
			if($post == NULL){
				global $post;
			}
			switch ( $post->post_type ) {
				case 'post':
					return get_permalink( $post );
				case 'page':
					return get_page_link( $post );
				case 'attachment':
					return get_attachment_link( $post );
				default: // For custom post types.
					return get_post_permalink( $post );
			}
		}

		/**
		 * Checks if URL regeneration is enabled for a specific post via its meta box setting.
		 * Regeneration is considered ON if the meta value is '0' or empty (default).
		 *
		 * @param \WP_Post|null $post Optional. The post object. Defaults to the global $post if null.
		 * @return bool True if regeneration is enabled for the post, false otherwise.
		 */
		private static function is_aurg_checkbox( \WP_Post $post = NULL )
		{
			if($post == NULL){
				global $post;
			}
			$meta_value = get_post_meta( $post->ID, 'aurg_checkbox', TRUE );
			// Empty meta value means "On" (default), '0' also means "On". '1' means "Off".
			return ( empty( $meta_value ) || $meta_value === "0" );
		}

		/**
		 * Checks if a post is not of an incomplete status (e.g., draft, pending).
		 *
		 * @param \WP_Post|null $post Optional. The post object. Defaults to the global $post if null.
		 * @return bool True if the post status is not incomplete, false otherwise.
		 */
		private static function is_not_incomplete_post_type( \WP_Post $post = NULL )
		{
			if($post == NULL){
				global $post;
			}
			// Incomplete statuses that should not have URL regeneration applied.
			return !in_array( get_post_status( $post ), array( 'draft', 'pending', 'auto-draft', 'future' ) );
		}

		/**
		 * Checks if the WordPress permalink structure includes '%postname%'.
		 * The plugin relies on this for its URL manipulation.
		 *
		 * @global \WP_Rewrite $wp_rewrite WordPress rewrite object.
		 * @return bool True if %postname% is found in the permalink structure, false otherwise.
		 */
		private static function is_include_postname(){
			global $wp_rewrite;
			return strpos($wp_rewrite->permalink_structure, '%postname%') !== false;
		}

		/**
		 * Handles plugin deactivation.
		 * Sets the global 'aurg_enable' option to '0' (disabled) and flushes rewrite rules.
		 *
		 * @return void
		 */
		public function deactivation_hook()
		{
			// Ensure self::$options is an array before trying to modify it.
			if ( !is_array(self::$options) ) {
				self::$options = get_option( 'aurg_options' ); // Re-fetch if not an array (should be by constructor).
				if ( !is_array(self::$options) ) {
					self::$options = array(); // Still not an array, so initialize.
				}
			}
			self::$options['aurg_enable'] = '0'; // Disable the plugin.
			update_option( 'aurg_options', self::$options, FALSE );
			self::add_rewrite_rules(TRUE); // Force flush rewrite rules.
		}
	}
endif;

/**
 * Instantiates the Auto_URL_Regenerator class.
 * This function is hooked to 'after_setup_theme' to ensure the plugin initializes
 * after the theme and other core WordPress functionalities are ready.
 *
 * @return void
 */
function Auto_URL_Regenerator()
{
	new Auto_URL_Regenerator();
}

/**
 * Initialize this plugin once all other plugins have finished loading, and theme is set up.
 */
add_action( 'after_setup_theme', 'Auto_URL_Regenerator', 99);
