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
 * Load plugin textdomain.
 *
 * @since 1.0.0
 */
function aurg_load_textdomain() {
	load_plugin_textdomain( 'autourlregenerator', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'plugins_loaded', 'aurg_load_textdomain' );

if ( !class_exists( 'Auto_URL_Regenerator' ) ) :
	class Auto_URL_Regenerator
	{
		private static $default_post_type = array( 'post', 'page', 'attachment' );

		private static $dotw = array();

		private static $interval_week = array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' );

		private static $options;

		const NONCE_ACTION = 'aurg_option_setting';

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

			self::$options = get_option( 'aurg_options' );

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
		
		public function __destruct()
		{
		}


		public function admin_menu()
		{
			$options_hook = add_options_page( __( 'Auto URL Regenerator', 'autourlregenerator' ), __( 'Auto URL Regenerator', 'autourlregenerator' ),'manage_options','aurg_options', '__return_NULL' );
			if($options_hook)
			{
				add_action("load-{$options_hook}", array(__CLASS__,'admin_load_aurg_options' ) );
			}
		}


		public static function admin_load_aurg_options()
		{
			$screen = get_current_screen();
			$hook_suffix = $screen->id;
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


		public function admin_aurg_option_save($action)
		{
			if ($action == 'save' ) {
				
				check_admin_referer(self::NONCE_ACTION);

				if(isset($_POST['aurg_enable']) && $_POST['aurg_enable'] === '1' ){
					self::$options['aurg_enable'] = '1';
					$flush = FALSE;
				}else{
					self::$options['aurg_enable'] = '0';
					$flush = TRUE;
				}

				if(is_array($_POST['aurg_post_type']) ){
					foreach($_POST['aurg_post_type'] as $value){
						self::$options['aurg_post_type'][] = sanitize_text_field($value);
					}
				}else{
					self::$options['aurg_post_type'] = array();
				}

				$post_types = get_post_types( array( 'public' => TRUE), 'object', 'and' );
				$before_interval = self::get_aurg_interval();

				foreach ($post_types as $value) {
					self::$options['aurg_interval'][$value->name]= array(
						'interval_kind' => sanitize_text_field( $_POST[$value->name.'_interval_kind'] ),
						'interval_hour' => sanitize_text_field( $_POST[$value->name.'_interval_hour'] ),
						'interval_week' => sanitize_text_field( $_POST[$value->name.'_interval_week'] ),
						'interval_day'  => sanitize_text_field( $_POST[$value->name.'_interval_day'] ),
						'interval_salt' => ($_POST[$value->name.'_interval_kind'] === '3' ) ? hash_hmac( 'sha256', mt_rand(),$before_interval[$value->name.'_interval_kind']):$before_interval[$value->name]['interval_salt'],
					);
				}

				update_option( 'aurg_options',self::$options,FALSE);
				$redirect_uri = add_query_arg( 'setting', 'saved' );
				self::add_rewrite_rules($flush);
				wp_redirect($redirect_uri);
				exit();
			}
		}

		
		public static function admin_aurg_option_content()
		{
			$message = NULL;
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
						<?php $post_types = array_merge($post_type_list['default'],$post_type_list['custom']);?>
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
		

		public function aurg_register_scripts()
		{
			wp_register_style( 'aurg_style', plugins_url( '', __FILE__ ) . '/admin.css', array(), AUTO_URL_REGENERATOR_CURRENT_VERSION );
			wp_register_script( 'aurg_script', plugins_url( '', __FILE__ ) . '/admin.js', array('jquery'), AUTO_URL_REGENERATOR_CURRENT_VERSION, true );
		}


		public static function aurg_enqueue_scripts()
		{
			wp_enqueue_style( 'aurg_style' );
			wp_enqueue_script( 'aurg_script' );
		}
		

		public function plugin_action_links( $links, $file )
		{
			if ( $file == 'auto-url-regenerator/' . basename(__FILE__) ) {
				$settings_link = '<a href="options-general.php?page=aurg_options">' . __( 'Settings', 'autourlregenerator' ) . '</a>';
				$links = array_merge( array( $settings_link ), $links );
			}
			return $links;
		}


		public function admin_notice_aurg_disable()
		{
			$screen = get_current_screen();
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


		private function set_interval()
		{
			$aurg_post_type = self::get_aurg_post_type();

			foreach($aurg_post_type as $value){
				$aurg_interval = self::get_aurg_interval($value);
				$timezone = get_option( 'timezone_string' );
				$date = new DateTime();
				$date->setTimezone(new DateTimeZone($timezone) );
				switch($aurg_interval['interval_kind']){
					case 0:
						$date->modify($aurg_interval['interval_hour'].'hour ago' );
						$date->modify( 'today' );
						self::$options['hash_values'][$value] = hash_hmac( 'sha256', $date->format(DateTime::ATOM), $value);
						break;
					case 1:
						$date->modify( 'tomorrow' );
						$date->modify( 'last '.self::$interval_week[$aurg_interval['interval_week']]);
						self::$options['hash_values'][$value] = hash_hmac( 'sha256', $date->format(DateTime::ATOM), $value);
						break;
					case 2:
						$date->modify( 'tomorrow' );
						if($aurg_interval['interval_day'] < 29){
							$date->modify($aurg_interval['interval_day'].'day ago' );
						}else{
							$date->modify( 'last month' );
						}
						$date->modify( 'first day of last month' );
						self::$options['hash_values'][$value] = hash_hmac( 'sha256', $date->format(DateTime::ATOM), $value);
						break;
					case 3:
						self::$options['hash_values'][$value] = hash_hmac( 'sha256',$aurg_interval['interval_salt'], $value);
						break;
				}
			}
			update_option( 'aurg_identifier', self::$options, FALSE);
		}
		

		public function add_rewrite_rules( $flush = FALSE )
		{
			if($flush === TRUE || !self::is_include_postname()){
				return flush_rewrite_rules();
			}

			global $wp_rewrite;
			$post_type_list = self::get_post_type_list();
			$aurg_post_type = self::get_aurg_post_type();

			foreach ($post_type_list['custom'] as $value) {
				if (in_array($value->name, $aurg_post_type)) {
					add_rewrite_rule($value->name.'/(.+)-[0-9a-f]{8}/?$', 'index.php?post_type=' . $value->name . '&' . $value->name . '=$matches[1]', 'top');
				}
			}

			foreach ($post_type_list['default'] as $value) {
				if (in_array($value->name, $aurg_post_type)) {
					switch ($value->name) {
						case 'post':
							$regex = ltrim($wp_rewrite->permalink_structure, '/');
							$n = 0;
							foreach ($wp_rewrite->rewritecode as $key => $value) {
								if ($value === '%postname%') {
									$regex = str_replace($value, $wp_rewrite->rewritereplace[$key].'-[0-9a-f]{8}', $regex);
									$n++;
									$query[$n] = 'name=$matches['.$n.']';
								} else {
									$regex = str_replace($value, $wp_rewrite->rewritereplace[$key], $regex, $cnt);
									if ($cnt >= 1) {
										$n++;
										$query[$n] = str_replace('%', '', $value).'=$matches['.$n.']';
									}
								}
							}
							$regex = rtrim($regex, '/').'(?:/([0-9]+) )?/?$';
							$n++;
							$query[$n] = 'page=$matches['.$n.']';
							$redirect .= 'index.php?'.implode('&', $query);
							add_rewrite_rule($regex, $redirect, 'top');
							break;
						case 'page':
						case 'attachment':
							add_rewrite_rule('(.+)-p[0-9a-f]{8}?$', 'index.php?pagename=$matches[1]', 'top');
							break;
					}
				}
			}
			flush_rewrite_rules();
		}


		public function get_post_link_to_support($permalink, \WP_Post $post, $leavename)
		{
			$aurg_post_type = self::get_aurg_post_type();
			if (in_array( 'post',$aurg_post_type) && self::is_not_incomplete_post_type( $post ) && self::is_include_postname() && self::is_aurg_checkbox( $post ) ){
				$identifier = $this->get_identifier( $post );
				$permalink = str_replace($post->post_name,$post->post_name.'-'.$identifier,$permalink);
			}
			return $permalink;
		}


		public function get_page_link_to_support($link, $post_id, $sample)
		{
			$post = get_post($post_id);
			$aurg_post_type = self::get_aurg_post_type();
			if (in_array( 'page',$aurg_post_type) && self::is_not_incomplete_post_type( $post ) && self::is_include_postname() && self::is_aurg_checkbox( $post ) ){
				$identifier = $this->get_identifier( $post );
				$link = str_replace($post->post_name,$post->post_name.'-p'.$identifier,$link);
			}
			return $link;
		}


		public function get_attachment_link_to_support($link, $post_id)
		{
			$post = get_post($post_id);
			$aurg_post_type = self::get_aurg_post_type();
			if (in_array( 'attachment',$aurg_post_type) && self::is_include_postname() && self::is_aurg_checkbox( $post ) ){	
				$identifier = $this->get_identifier( $post );
				$link = str_replace($post->post_name,$post->post_name.'-p'.$identifier,$link);
			}
			return $link;
		}


		public function get_post_type_link_to_support($post_link, \WP_Post $post, $leavename, $sample)
		{
			$aurg_post_type = self::get_aurg_post_type();
			if (in_array($post->post_type,$aurg_post_type) && self::is_not_incomplete_post_type( $post ) && self::is_include_postname() && self::is_aurg_checkbox( $post ) ){
				$identifier = $this->get_identifier( $post );
				$post_link = str_replace($post->post_name,$post->post_name.'-'.$identifier,$post_link);
			}
			return $post_link;
		}


		private function get_identifier($post = NULL)
		{
			if($post == NULL){
				global $post;
			}
			$aurg_identifier = self::get_aurg_identifier($post);
			return mb_substr(hash_hmac( 'sha256', $post->post_name, $aurg_identifier), 0, 8);
		}


		public function add_aurg_checkbox()
		{
			$aurg_post_type = self::get_aurg_post_type();
			foreach ($aurg_post_type as $value) {
				add_meta_box( 'aurg_checkbox', __( 'URL Automatic Update Setting', 'autourlregenerator' ), array($this, 'insert_aurg_checkbox_field' ), $value, 'normal' );
			}
		}


		public function insert_aurg_checkbox_field()
		{
			global $post;
			$checked = (self::is_aurg_checkbox( $post ) ) ? TRUE : FALSE;
			?>
				
			<input type="radio" name="aurg_checkbox" value="0"<?php echo esc_attr(($checked) ? ' checked="checked"' : '');?>><?php _e( 'On', 'autourlregenerator' ); ?>
			<input type="radio" name="aurg_checkbox" value="1"<?php echo esc_attr(($checked) ? '' : ' checked="checked"');?>> <?php _e( 'Off', 'autourlregenerator' ); ?>
			<?php
		}


		public function save_aurg_checkbox_fields( $post_id )
		{
			if(isset($_POST['aurg_checkbox']) ){
				update_post_meta($post_id, 'aurg_checkbox', sanitize_text_field($_POST['aurg_checkbox'] ) );
			}
		}


		public function redirect_correct_url()
		{
			global $post;
			$aurg_post_type = self::get_aurg_post_type();
			if( is_singular() && in_array( $post->post_type, $aurg_post_type ) && self::is_not_incomplete_post_type( $post ) && self::is_include_postname() ){
				$http = is_ssl() ? 'https://' : 'http://';
				$url = $http . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"];
				$url = parse_url($url);
				if(self::is_aurg_checkbox( $post ) ){
					$identifier = $this->get_identifier( $post );
					if(!strpos( $url['path'], $identifier ) ){
						$url = self::get_redirect_correct_url( $post );
						wp_redirect( $url, 301 );
						exit;
					}
				}else{
					if(preg_match("/\/(.+)-p?[0-9a-f]{8}/", $url['path']) ){
						$url = self::get_redirect_correct_url( $post );
						wp_redirect( $url, 301 );
						exit;
					}
				}
			}
			return;
		}


		private static function get_post_type_list()
		{
			$post_types = get_post_types( array( 'public' => TRUE), 'object', 'and' );
			$default_post_type = self::$default_post_type;
			foreach($post_types as $value){
				if(in_array($value->name,$default_post_type) ){
					$post_type_list['default'][] = $value;
				} else {
					$post_type_list['custom'][] = $value;
				}
			}
			return $post_type_list;
		}


		private static function get_aurg_enable()
		{
			return ( isset(self::$options['aurg_enable']) && self::$options['aurg_enable'] == '1' ) ? '1' : '0';
		}


		private static function get_aurg_post_type()
		{
			return ( isset(self::$options['aurg_post_type']) && self::$options['aurg_post_type'] ) ? self::$options['aurg_post_type'] : array();
		}


		private static function get_aurg_interval( $post_type = NULL )
		{
			if(!isset( $post_type ) ){
				return ( isset(self::$options['aurg_interval']) && self::$options['aurg_interval'] ) ? self::$options['aurg_interval'] : array();
			}
			$option = self::get_aurg_interval();
			foreach($option as $key => $value){
				if($post_type === $key){
					return $value;
				}
			}
			return FALSE;
		}


		private static function get_aurg_identifier( $post_type = NULL )
		{
			if(!isset( $post_type ) ){
				return ( isset(self::$options['aurg_identifier']) && self::$options['aurg_identifier'] ) ? self::$options['aurg_identifier'] : array();
			}
			$option = self::get_aurg_identifier();
			foreach($option as $key => $value){
				if($post_type === $key){
					return $value;
				}
			}
			return FALSE;
		}


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
				default:
					return get_post_permalink( $post );
			}
		}


		private static function is_aurg_checkbox( \WP_Post $post = NULL )
		{
			if($post == NULL){
				global $post;
			}
			return ( empty( get_post_meta( $post->ID, 'aurg_checkbox', TRUE ) ) || get_post_meta( $post->ID, 'aurg_checkbox', TRUE ) === "0" );
		}


		private static function is_not_incomplete_post_type( \WP_Post $post = NULL )
		{
			if($post == NULL){
				global $post;
			}
			return !in_array( get_post_status( $post ), array( 'draft', 'pending', 'auto-draft', 'future' ) );
		}

		private static function is_include_postname(){
			global $wp_rewrite;
			return strpos($wp_rewrite->permalink_structure, '%postname%');
		}


		public function deactivation_hook()
		{
			self::$options['aurg_enable'] = '0';
			update_option( 'aurg_options', self::$options, FALSE );
			self::add_rewrite_rules(TRUE);
		}
	}
endif;

function Auto_URL_Regenerator()
{
	new Auto_URL_Regenerator();
}

/**
 * Initialize this plugin once all other plugins have finished loading.
 */
add_action( 'after_setup_theme', 'Auto_URL_Regenerator', 99);
