<?php

/**
 * Plugin Name: Web Push Notifications
 * Plugin URI: http://www.tidionotification.com
 * Description: Grow the traffic on your website by sending push notifications, even when your users will close the browser window 
 * Version: 1.1
 * Author: Tidio Ltd.
 * Author URI: http://www.tidionotification.com
 * License: GPL2
 */
class TidioNotification {

	private $scriptUrl = '//code.tidio.co/';

	public function __construct() {
		self::getPrivateKey();

		add_action('admin_menu', array($this, 'addAdminMenuLink'));
		add_action('admin_footer', array($this, 'adminJS'));

		if (!is_admin()) {
			add_action('wp_enqueue_scripts', array($this, 'enqueueScripts'));
		}

		add_action('deactivate_' . plugin_basename(__FILE__), array($this, 'uninstall'));
		add_action('wp_ajax_tidio_notification_redirect', array($this, 'ajaxTidioNotificationRedirect'));
		add_action('add_meta_boxes_post', array($this, 'addMetaBoxes'));
		add_action('save_post', array($this, 'savePostAction'));
	}

	// Ajax - Create New Project

	public function ajaxTidioNotificationRedirect() {

		if (!empty($_GET['access_status']) && !empty($_GET['private_key']) && !empty($_GET['public_key'])) {

			update_option('tidio-notification-external-public-key', $_GET['public_key']);
			update_option('tidio-notification-external-private-key', $_GET['private_key']);

			$view = array(
				'mode' => 'redirect',
				'redirect_url' => self::getRedirectUrl($_GET['private_key'])
			);
		} else {

			$view = array(
				'mode' => 'access_request',
				'access_url' => self::getAccessUrl()
			);
		}
		require "views/ajax-tidio-notification-redirect.php";
		exit;
	}

	// Front End Scripts

	public function enqueueScripts() {
		wp_enqueue_script('tidio-notification', $this->scriptUrl . self::getPublicKey() . '.js', array(), '1.0.0', true);
	}

	// Admin JavaScript

	public function adminJS() {

		$privateKey = self::getPrivateKey();
		$redirectUrl = '';

		if ($privateKey && $privateKey != 'false') {
			$redirectUrl = self::getRedirectUrl($privateKey);
		} else {
			$redirectUrl = admin_url('admin-ajax.php?action=tidio_notification_redirect');
		}

		echo "<script> jQuery('a[href=\"admin.php?page=tidio-notification\"]').attr('href', '" . $redirectUrl . "').attr('target', '_blank') </script>";
	}

	// Menu Pages

	public function addAdminMenuLink() {

		$optionPage = add_menu_page(
				'Notification', 'Notification', 'manage_options', 'tidio-notification', array($this, 'addAdminPage'), plugins_url('media/img/icon.png', __FILE__)
		);
	}

	public function addAdminPage() {
		// Set class property
		$dir = plugin_dir_path(__FILE__);
		include $dir . 'options.php';
	}

	public function addMetaBoxes($post) {

		$available_post_status = array('auto-draft', 'draft');
//		if (in_array($post->post_status, $available_post_status)) {
		add_meta_box(
				'tidio-notification-meta-box', __('Tidio Notification'), array($this, 'renderMetaBox'), 'post', 'side', 'high'
		);
//		}
	}

	public function renderMetaBox($post, $metabox) {

		// Add an nonce field so we can check for it later.
		wp_nonce_field('tidio_meta_box', 'tidio_meta_box_nonce');

		/*
		 * Use get_post_meta() to retrieve an existing value
		 * from the database and use the value for the form.
		 */
		$value = get_post_meta($post->ID, 'tidio_notification', true);

		if ($value == 'notice') {
			echo '<div class="updated">';
			echo '<p>' . __('Notification was sent!') . '</p>';
			echo '</div>';
			$value = 'true';
			update_post_meta($post->ID, 'tidio_notification', 'true');
		}

		$available_post_status = array('auto-draft', 'draft');
		
		if ($value == 'true' || !in_array($post->post_status, $available_post_status)) {
			// Already send
			echo '<p class="meta-options">';
			echo '<label for="tidio_notification_post">';
			if ($value == 'true') {
				_e('Notification was already send for this post');
			} else {
				_e('There was no notification for this post.');
			}
			echo '<div></div>';
			echo '</label> ';
//			echo '<input type="button" id="tidio_notification_post" name="tidio_notification_post" value="Send" />';
			echo '</p>';
		} else {
			echo '<p class="meta-options">';
			echo '<label for="tidio_notification_post">';
			echo '<input type="checkbox" id="tidio_notification_post" name="tidio_notification_post" checked />';
			_e('Notify users about this post');
			echo '</label> ';
			echo '</p>';
		}
	}

	public function savePostAction($post_id) {
		/*
		 * We need to verify this came from the our screen and with proper authorization,
		 * because save_post can be triggered at other times.
		 */

		// Check if our nonce is set.
		if (!isset($_POST['tidio_meta_box_nonce']))
			return $post_id;

		$nonce = $_POST['tidio_meta_box_nonce'];

		// Verify that the nonce is valid.
		if (!wp_verify_nonce($nonce, 'tidio_meta_box'))
			return $post_id;

		// If this is an autosave, our form has not been submitted,
		// so we don't want to do anything.
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
			return $post_id;

		// Check post type
		if ($_POST['post_type'] !== 'post')
			return $post_id;

		// Check the user's permissions.
		if ('post' == $_POST['post_type']) {
			if (!current_user_can('edit_post', $post_id))
				return $post_id;
		}

		// Check if it's a revision
		if (wp_is_post_revision($post_id))
			return $post_id;

		/* OK, its safe for us to save the data now. */
		if ($_POST['post_status'] == 'publish' && isset($_POST['tidio_notification_post'])) {

			// Send notification action
			$result = $this->sendNotification($post_id);
			// Update the meta field.
			if ($result)
				update_post_meta($post_id, 'tidio_notification', 'notice');
		}
	}

	public function sendNotification($post) {
		if (is_numeric($post)) {
			$post = get_post($post);
		}
		// request for tidio notification api
		$apiUrl = 'https://www.tidionotification.com/api/mailing/send';
		$data = array(
			'subject' => get_bloginfo(),
			'message' => 'New post: ' . PHP_EOL . $post->post_title,
			'url' => get_permalink($post->ID),
			'publicKey' => $this->getPublicKey(),
			'privateKey' => $this->getPrivateKey()
		);

		$url = $apiUrl . '?' . http_build_query($data);
		$respond = @file_get_contents($url);
		$respond = json_decode($respond, true);
		if (!is_array($respond)) {
			return false;
		}
		return $respond['status'];
	}

	// Uninstall

	public function uninstall() {
		
	}

	// Get Private Key

	public static function getPrivateKey() {

		$privateKey = get_option('tidio-notification-external-private-key');

		if ($privateKey) {
			return $privateKey;
		}

		@$data = file_get_contents(self::getAccessUrl());
		if (!$data) {
			update_option('tidio-notification-external-private-key', 'false');
			return false;
		}

		@$data = json_decode($data, true);
		if (!$data || !$data['status']) {
			update_option('tidio-notification-external-private-key', 'false');
			return false;
		}

		update_option('tidio-notification-external-private-key', $data['value']['private_key']);
		update_option('tidio-notification-external-public-key', $data['value']['public_key']);

		return $data['value']['private_key'];
	}

	// Get Access Url

	public static function getAccessUrl() {

		return 'http://www.tidionotification.com/access/create?url=' . urlencode(site_url()) . '&platform=wordpress&email=' . urlencode(get_option('admin_email')) . '&_ip=' . $_SERVER['REMOTE_ADDR'];
	}

	public static function getRedirectUrl($privateKey) {

		return 'https://external.tidionotification.com/access?privateKey=' . $privateKey;
	}

	// Get Public Key

	public static function getPublicKey() {

		$publicKey = get_option('tidio-notification-external-public-key');

		if ($publicKey) {
			return $publicKey;
		}

		self::getPrivateKey();

		return get_option('tidio-notification-external-public-key');
	}

	private function getUserIp() {
		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			//check ip from share internet
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			//to check ip is pass from proxy
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
			$ip = $_SERVER['REMOTE_ADDR'];
		}
		return $ip;
	}

}

$tidioNotification = new TidioNotification();

