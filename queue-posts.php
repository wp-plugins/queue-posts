<?php
/*
Plugin Name: Queue posts
Plugin URI: http://mediadk.dk/wp-plugins/queue-posts/
Description: Queue posts and pages for later publishing with the press of a button.
Version: 1.3
Author: Mediadk
Author URI: http://mediadk.dk
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=AHE8UEBKSYJCA
License: GPL2
	
	Copyright 2013 Mediadk  (email : tobias@mediadk.dk)
	
    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.
	
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.
	
    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
	
*/

require_once('functions.php');

// Make sure we don't expose any info if called directly
if ( !function_exists( 'add_action' ) ) {
	echo 'Hi there!  I\'m just a plugin, not much I can do when called directly.';
	exit;
}

// Allow redirection, even if my theme starts to send output to the browser
add_action('init', 'queue_posts_output_buffer');
function queue_posts_output_buffer() {
	ob_start();
}

add_action('admin_menu', 'queue_posts_admin_menu');
function queue_posts_admin_menu() {
	add_submenu_page('options-general.php', _('Queue Posts settings'), _('Queue Posts'), 'manage_options', 'queue-posts-settings', 'queue_posts_admin_page');
}

add_action('admin_head', 'queue_posts_head');
function queue_posts_head() {
	echo '<link rel="stylesheet" type="text/css" href="' . plugin_dir_url( __FILE__ ) . 'style.css">';
}

add_action('admin_footer', 'queue_posts_footer');
function queue_posts_footer() { ?>
<script type="text/javascript">
jQuery(document).ready(function($) {
	
	var oSubmitQueue = jQuery('<input type="button" name="publish-queue" id="publish-queue" class="button button-primary button-large button-queue" value="Queue"><input type="hidden" name="queue-posts-plugin-future-date">');
	
	jQuery('body.post-new-php #publishing-action').append( oSubmitQueue );
	
	jQuery('#publish-queue').click(function() {
		
		jQuery('body').css('cursor', 'wait');
		
		var data = {
			action: 'get_next_publish_time'
		};
		
		$.post(ajaxurl, data, function(response) {
			
			jQuery('input[name=queue-posts-plugin-future-date]').val( response );
			jQuery('form[name=post]').submit();
			
		});
		
	});
	
});
</script>
<?php }

add_action('wp_ajax_get_next_publish_time', 'get_next_publish_time_callback');
function get_next_publish_time_callback() {
	
	$posts = array_merge( get_posts('post_status=future'), get_pages('post_status=future') );

	$latest_date = 0;

	foreach ($posts as $post) {

		$date = strtotime($post->post_date);

		if ( $date > $latest_date ) {
			$latest_date = $date;
		}

	}

	if ( $latest_date == 0 ) {
		$latest_date = mktime();
	}
	
	echo $latest_date;
	
	die(); // This is required to return a proper result
	
}

add_filter('plugin_action_links', 'queue_posts_plugin_action_links', 10, 2);
function queue_posts_plugin_action_links($links, $file) {
	static $this_plugin;
	
	if (!$this_plugin) {
		$this_plugin = plugin_basename(__FILE__);
	}
	
	// check to make sure we are on the correct plugin
	if ($file == $this_plugin) {
		$settings_link = '<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=AHE8UEBKSYJCA">' . __('Donate') . '</a>';
		array_unshift($links, $settings_link);
		
		$settings_link = '<a href="' . get_bloginfo('wpurl') . '/wp-admin/options-general.php?page=queue-posts-settings.php">' . __('Settings') . '</a>';
		array_unshift($links, $settings_link);
	}
	
	return $links;
}

add_filter('wp_insert_post_data', 'queue_posts_insert_post_data', '99', 2);
function queue_posts_insert_post_data($data, $postarr) {
	
	if (( @$data['post_status'] == 'draft' || @$data['post_status'] == 'publish' || @$data['post_status'] == 'future' ) && ( @$data['post_type'] == 'post' || @$data['post_type'] == 'page' )) {
		
		$transferred_future_date = @floatval( $postarr['queue-posts-plugin-future-date'] );
		$future_date = @floatval( $postarr['queue-posts-plugin-future-date'] );
		
		if ( intval($future_date) != 0 && is_numeric($future_date) ) {
			
			$iQueueTimeFrom        = @intval(getQueueTimeFromSetting());
			$iQueueTimeTo          = @intval(getQueueTimeToSetting());
			$iMinimumInterval      = getQueueMinimumInterval();
			$iMinimumIntervalType  = getQueueMinimumIntervalType();
			
			if ( $future_date < mktime() ) {
				/* Make sure the future date is not in the past. */
				$future_date = mktime();
			}
			
			if ( $iMinimumIntervalType == 'h' ) {
				/* Convert hours to minutes. */
				$iMinimumInterval = $iMinimumInterval * 60;
			}
			$iMinimumInterval = intval($iMinimumInterval);
			
			$future_date = $future_date + ($iMinimumInterval * 60) + rand(0, (60 * 60));
			
			if ( date('G', $future_date) < $iQueueTimeFrom || date('G', $future_date) > $iQueueTimeTo ) {
				$new_future_date = $transferred_future_date + (60 * 60 * 24);
				
				$future_date = mktime($iQueueTimeFrom, rand(0, 59), rand(0, 59), date('n', $new_future_date), date('j', $new_future_date), date('Y', $new_future_date));
			}
			
			$future_date_gmt = $future_date - (60*60);
			
			$data['post_status']   = 'future';
			$data['post_date']     = date('Y-m-d H:i:s', $future_date);
			$data['post_date_gmt'] = date('Y-m-d H:i:s', $future_date_gmt);
			
		}
		
	}
	
	return $data;
	
}

function queue_posts_admin_page() {
	
	if ( isset($_POST['queue-time-from']) ) {
		$iQueueTimeFrom       = $_POST['queue-time-from'];
		$iQueueTimeTo         = $_POST['queue-time-to'];
		$iMinimumInterval     = $_POST['minimum-interval'];
		$iMinimumIntervalType = $_POST['minimum-interval-type'];
		
		$iMinimumInterval     = str_replace( ',', '.', $iMinimumInterval );
		if ( !is_numeric($iMinimumInterval) ) {
			$iMinimumInterval = 0;
		}
		
		update_option('queue_posts_time_from', $iQueueTimeFrom);
		update_option('queue_posts_time_to', $iQueueTimeTo);
		update_option('queue_posts_minimum_interval', $iMinimumInterval);
		update_option('queue_posts_minimum_interval_type', $iMinimumIntervalType);
		
		wp_redirect('?page=' . $_GET['page'] . '&msg=saved');
		
		exit();
	}
	
	$iQueueTimeFrom       = getQueueTimeFromSetting();
	$iQueueTimeTo         = getQueueTimeToSetting();
	$iMinimumInterval     = getQueueMinimumInterval();
	$iMinimumIntervalType = getQueueMinimumIntervalType();
	?>
	
	<div class="wrap">
		
		<h2>
			<img alt="Settings" src="<?php echo plugin_dir_url( __FILE__ ); ?>img/settings-icon.png" width="32" height="32" style="margin-bottom: -7px;">
			<?php echo _('Settings for') . ' ' . _('Queueing Posts'); ?>
		</h2>
		
		<form method="post" action="" onsubmit="return queuePostsPluginValidateSettings();">
			
			<p style="margin: 15px 0;">
				<label for="queue-posts-time-from">
					<?php echo _('Publish between'); ?>:
				</label>
				
				<br><br>
				
				<select id="queue-posts-time-from" name="queue-time-from">
					<option>00</option>
					<option>01</option>
					<option>02</option>
					<option>03</option>
					<option>04</option>
					<option>05</option>
					<option>06</option>
					<option>07</option>
					<option>08</option>
					<option>09</option>
					<option>10</option>
					<option>11</option>
					<option>12</option>
					<option>13</option>
					<option>14</option>
					<option>15</option>
					<option>16</option>
					<option>17</option>
					<option>18</option>
					<option>19</option>
					<option>20</option>
					<option>21</option>
					<option>22</option>
					<option>23</option>
				</select>
				
				&mdash;
				
				<select id="queue-posts-time-to" name="queue-time-to">
					<option>01</option>
					<option>02</option>
					<option>03</option>
					<option>04</option>
					<option>05</option>
					<option>06</option>
					<option>07</option>
					<option>08</option>
					<option>09</option>
					<option>10</option>
					<option>11</option>
					<option>12</option>
					<option>13</option>
					<option>14</option>
					<option>15</option>
					<option>16</option>
					<option>17</option>
					<option>18</option>
					<option>19</option>
					<option>20</option>
					<option>21</option>
					<option>22</option>
					<option>23</option>
				</select>
			</p>
			
			<p style="margin: 15px 0;">
				<label for="queue-posts-minimum-interval">
					<?php echo _('Minimum time between posts'); ?>:
				</label>
				
				<br><br>
				
				<input id="queue-posts-minimum-interval" name="minimum-interval" type="text" maxlength="4" value="<?php echo $iMinimumInterval; ?>" style="width: 40px;">
				<select id="queue-posts-minimum-interval-type" name="minimum-interval-type">
					<option value="h"><?php echo _('hour(s)'); ?></option>
					<option value="m"><?php echo _('minute(s)'); ?></option>
				</select>
			</p>
			
			<p>
				<input type="submit" value="<?php echo _('Save'); ?>">
			</p>
			
		</form>
		
	</div>
	
	<script type="text/javascript">
	jQuery(document).ready(function() {
		
		jQuery('#queue-posts-time-from').val('<?php echo $iQueueTimeFrom; ?>');
		jQuery('#queue-posts-time-to').val('<?php echo $iQueueTimeTo; ?>');
		jQuery('#queue-posts-minimum-interval').val('<?php echo $iMinimumInterval; ?>');
		jQuery('#queue-posts-minimum-interval-type').val('<?php echo $iMinimumIntervalType; ?>');
		
		<?php if ( @$_GET['msg'] == 'saved' ) : ?>
			showMessage('<?php echo _('Your settings have been saved.'); ?>');
		<?php endif; ?>
		
		/* Allow only numeric characters typed in the Minimum Interval field. */
		jQuery('#queue-posts-minimum-interval').keydown(function(event) {
			// Allow: backspace, delete, decimal, left navigation, right navigation, tab, escape, and enter
			if ( event.keyCode == 46 || event.keyCode == 8 || event.keyCode == 190 || event.keyCode == 188 || event.keyCode == 37 || event.keyCode == 39 || event.keyCode == 9 || event.keyCode == 27 || event.keyCode == 13 || 
				// Allow: Ctrl+A
				(event.keyCode == 65 && event.ctrlKey === true) || 
				// Allow: home, end, left, right
				(event.keyCode >= 35 && event.keyCode <= 39)) {
					// let it happen, don't do anything
				return;
			} else {
				// Ensure that it is a number and stop the keypress
				if (event.shiftKey || (event.keyCode < 48 || event.keyCode > 57) && (event.keyCode < 96 || event.keyCode > 105 )) {
					event.preventDefault();
				}
			}
		});
		
	});
	
	function queuePostsPluginValidateSettings() {
		
		var oTimeFrom     = jQuery('#queue-posts-time-from');
		var oTimeTo       = jQuery('#queue-posts-time-to');
		var oInterval     = jQuery('#queue-posts-minimum-interval');
		var oIntervalType = jQuery('#queue-posts-minimum-interval-type');
		
		if ( parseInt(oTimeFrom.val()) >= parseInt(oTimeTo.val()) ) {
			showError('<?php echo _('&quot;Publish from&quot; time cannot be larger than or equal to &quot;Publish to&quot; time.'); ?>');
			oTimeFrom.focus();
			return false;
		}
		
		var iIntervalHours = oInterval.val();
		if ( oIntervalType.val() == 'm' ) {
			iIntervalHours = iIntervalHours / 60;
		}
		
		if ( iIntervalHours >= ( parseInt(oTimeTo.val()) - parseInt(oTimeFrom.val()) ) ) {
			showError('<?php echo _('The &quot;Minimum time between posts&quot; cannot be larger than or equal to the &quot;Publish between&quot; time.'); ?>');
			oInterval.focus();
			return false;
		}
		
		return true;
		
	}
	
	function showError(s) {
		
		var oMsgBox = jQuery('#message');
		
		if ( oMsgBox.length ) {
			oMsgBox.addClass('error');
			oMsgBox.removeClass('updated');
		} else {
			oMsgBox = jQuery('<div id="message" class="error"></div>');
			jQuery('.wrap form').prepend( oMsgBox );
		}
		
		oMsgBox.html(s);
		
	}
	
	function showMessage(s) {
		
		var oMsgBox = jQuery('#message');
		
		if ( oMsgBox.length ) {
			oMsgBox.addClass('updated');
			oMsgBox.removeClass('error');
		} else {
			oMsgBox = jQuery('<div id="message" class="updated"></div>');
			jQuery('.wrap form').prepend( oMsgBox );
		}
		
		oMsgBox.html(s);
		
	}
	</script>
	
	<?php
	
}

?>