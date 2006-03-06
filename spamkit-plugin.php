<?php
/*
Plugin Name: SpamKit Plugin
Plugin URI: http://blog.lobstertechnology.com/category/wordpress/plugins/spamkit/
Description: Prototype, uses <a href='http://webofshite.com/?p=3'>Time-Based-Tokens</a> in the comment form [by <a href='http://webofshite.com/'>Gerard Calderhead</a>]. If Wordpress receives a comment-post without the token, or with an invalid token the comment is held for moderation. In this version, there are no option pages or any visual aspects to this plugin.
Version: 0.4
Author: Michael Cutler
Author URI: http://blog.lobstertechnology.com/
Update: http://blog.lobstertechnology.com/category/wordpress/plugins/spamkit/
*/
/*
 * CHANGELOG:
 * 
 * 06/03/2006  Released as version 0.4
 *             Added options page, this required sanity checks to prevent double definition of functions, implemented in a C-style #ifdef / #define pattern.
 *             Added full configuration functionality, this is done using built-in defaults, overridden by saved options making it upgrade proof.
 *             Added new EXPERIMENTAL check, comments posted by clients with no User-Agent string are auto-spammed and dont make it to the moderation page.
 *             Added new EXPERIMENTAL check, submitted email address is subject to format validation & DNS check for a mail exchanger.
 *             Updated to use Gerry's new OO-based TBT code removing the dependancy on MCRYPT.
 *             Removed any path-dependant problems, making it compatible with all WP installs *i hope*.
 *             Added option to place trackback & pingbacks in the moderation queue, disabling this option causes them to be auto approved.
 *             Added option to moderate comments which fail TBT checks, disabling this option will mean the comments are automatically marked as spam and will never be seen.
 * 
 * 14/02/2006  Released as version 0.3
 *             Added an automated pingback to author on activation of the plugin (installation counter)
 *             Added check which will fail a TBT submitted within 5 seconds
 *             Added 'SpamKit has caught N spam comments' badge image functionality
 *             Released as version 0.2
 *             Updated Gerry's TBT code which now incorporates IP's into the validation 
 * 
 * 
 * KNOWN ISSUES:
 * 
 * 06/03/2006  Because direct calls to this script (for the badge) cannot access WP or any options, there is no easy way to provide a configurable /tmp directory. There is however a configuration option to disable this functionality if it causes problems.
 * 
 */
/* spamkit-plugin.php :: top
 * 
 * (c) 2005-2006 Michael Cutler (m@cotdp.com)
 *
 * You may distribute under any terms and any license of
 * your choosing providing you credit the author for his work.
 * 
 */
if( ! defined("spamkit-plugin.php") ) {
   
   define( "spamkit-plugin.php", true );
   
   $spamkit_default_options = array(
      'minimum_timeout' => 5,
      'maximum_timeout' => 3600,
      'trust_local_host' => true,
      'moderate_tbt_fails' => true,
      'moderate_trackbacks' => true,
      'enable_badge' => true,
      'experimental' => false
   );
   
   if ( function_exists("get_option") ) {
      $spamkit_options = get_option("spamkit_options");
      
      // stores the default settings
      if( empty($spamkit_options) ) {
         add_option("spamkit_options", $spamkit_default_options);
         $spamkit_options = get_option("spamkit_options");
      }
      
      // merge defaults with the settings in the DB
      foreach ( array_keys($spamkit_default_options) as $key ) {
         if ( strlen($spamkit_options[$key]) == 0 )
            $spamkit_options[$key] = $spamkit_default_options[$key];
      }
   }
 



   /**
    * Called by Wordpress while the comment form is being displayed, allows you to append XHTML
    * before the closing "</form>".
    *
    * Used here to insert a hidden form field with the SpamKit generated TimeToken
    *
    * @return nothing
    */
   function spamkit_action_comment_form() {
      $token = new TimeBasedToken( DB_PASSWORD );
      echo "<input type='hidden' name='token' value='" . $token->generateToken() . "'/>\n";
   }




   /**
    * Called by Wordpress immediately before the comment is added to the database allowing you
    * to override the 'approved' status of the comment.
    *
    * Here we use the SpamKit to determine if the TimeToken is valid. If invalid the comment will
    * go into the database flagged as "spam" not normally visible within the Wordpress Admin screen.
    *
    * This also means there is no email notification generated.
    *
    * @param $approved is the current 'approved' status of the comment.
    * @return "spam" if SpamKit finds the TimeToken invalid, otherwise $approved is returned unchanged
    */
   function spamkit_action_pre_comment_approved( $approved ) {
      global $spamkit_options, $spamkit_restore_comment;
   	  // comment is most likely a trackback from my own blog / server
   	  // this could be abused if another web application on the server
   	  // is exploited allowing an attacker to post comments apparently
   	  // from this server
   	  //
      
      // this controls wether or not the later call to spamkit_action_comment_post()
      // restores the comment status of if its completely ignored
      $spamkit_restore_comment = true;
        
   	  // skips time-based token checking
      if ( $spamkit_options['trust_local_host'] )
   	     if ( $_SERVER['REMOTE_ADDR'] == $_SERVER['SERVER_ADDR'] )
   	  	  return $approved;
      
      // Experimental checks
      if ( $spamkit_options['experimental'] ) {
         
         // No User-Agent header
         if ( strlen( $_SERVER['HTTP_USER_AGENT'] ) == 0 ) {
            $spamkit_restore_comment = false;
            return "spam";
         }
         
         // Validate email address
         if ( spamkit_experimental_test_author_email( $_POST['email'] ) == false ) {
            $spamkit_restore_comment = false;
            return "spam";
         }
         
      }
           
      // TBT checks
      $token = new TimeBasedToken( DB_PASSWORD );
      $token->setToken( $_POST["token"] );
      
      // fail immediately if the token is invalid
      if ( $token->isValid() == FALSE ) {
         $approved = "spam";
         if ( $spamkit_options['moderate_tbt_fails'] != true )
            $spamkit_restore_comment = false;
      }
         
      // fail if the token is less than 5 seconds old
      if ( $token->checkTimeout( $spamkit_options['minimum_timeout'] ) == FALSE ) {
         $approved = "spam";
         if ( $spamkit_options['moderate_tbt_fails'] != true )
            $spamkit_restore_comment = false;
      }
         
      // fail if the token is more than 60 minutes old
      if ( $token->checkTimeout( $spamkit_options['maximum_timeout'] ) == TRUE ) {
         $approved = "spam";
         if ( $spamkit_options['moderate_tbt_fails'] != true )
            $spamkit_restore_comment = false;
      }
      
      return $approved;
   }




   /**
    * Called by Wordpress after the comment has been added to the database
    *
    * Here we reset the approved status to '0' ie 'Awaiting Moderation' so the comment appears in
    * the admin screen for moderation but doesnt trigger an email notification. ( Bit of a hack )
    *
    * @param $comment_id is the database id of the inserted comment.
    * @param $approved is the current 'approved' status of the comment.
    * @return nothing
    */
   function spamkit_action_comment_post( $comment_id, $approved ) {
      global $wpdb, $spamkit_options, $spamkit_restore_comment;
      
      if ( $approved == "spam" ) {
         if ( $spamkit_restore_comment ) {
            // Update the comment
            $wpdb->query("UPDATE $wpdb->comments SET comment_approved = '0' WHERE comment_ID = '$comment_id';");
         } else {
            $approved = $spamkit_options['moderate_trackbacks'] ? 0 : 1;
            $wpdb->query("UPDATE $wpdb->comments SET comment_approved = '$approved' WHERE comment_ID = '$comment_id' AND ( comment_type='trackback' OR comment_type='pingback' );");
         }
      }
      
      if ( $spamkit_options['enable_badge'] ) {      
         // Fetch the count
         $row = $wpdb->get_row("SELECT COUNT(`comment_ID`) AS count FROM $wpdb->comments WHERE `comment_approved` = 'spam';");
   
         // Write the count to temp file         
         $name = sprintf('%08X', crc32($_SERVER['SERVER_NAME']));
         $handle = fopen( "/tmp/spamkit-" . $name, "w" );
         if ( $handle ) {
            fwrite( $handle, $row->count );
            fclose( $handle );
         }
      }
   }




   /**
    * 
    * @param $comment_id is the database id of the inserted comment.
    * @param $comment_status is the current 'approved' status of the comment.
    * @return nothing
    */
   function spamkit_action_set_comment_status ( $comment_id, $comment_status ) {
      global $wpdb, $spamkit_options;

      if ( $spamkit_options['enable_badge'] ) {      
         // Fetch the count
         $row = $wpdb->get_row("SELECT COUNT(`comment_ID`) AS count FROM $wpdb->comments WHERE `comment_approved` = 'spam';");
   
         // Write the count to temp file         
         $name = sprintf('%08X', crc32($_SERVER['SERVER_NAME']));
         $handle = fopen( "/tmp/spamkit-" . $name, "w" );
         if ( $handle ) {
            fwrite( $handle, $row->count );
            fclose( $handle );
         }
      }
      
   }
   
   
   
   
   /**
    * 
    * @return count from file
    */
   function spamkit_ping_activate() {
      global $wp_version;
      include_once (ABSPATH . WPINC . '/class-IXR.php');
      $server = 'blog.lobstertechnology.com';
      $path   = '/xmlrpc';
      // using a timeout of 3 seconds should be enough to cover slow servers
      $client = new IXR_Client($server, ((!strlen(trim($path)) || ('/' == $path)) ? false : $path));
      $client->timeout = 3;
      $client->useragent .= ' -- WordPress/'.$wp_version;
   
      // when set to true, this outputs debug messages by itself
      $client->debug = false;
      $home = trailingslashit( get_option('home') );
      $client->query('plugin_manager.ping', "spamkit-plugin", "0.4", get_settings('blogname'), $home);
   }
   
  
   
   
   /**
    * This method validates the given email address is of the format <something>@<something>.<something>
    * It then checks the domain portion of the address to see if it has a mail exchanger
    * 
    * @return true if the email address passed validation, otherwise false
    */
    function spamkit_experimental_test_author_email( $email ) {
      // format validation
      $matches = array();
      preg_match( "/^(.*)\@([A-Za-z0-9\-\.]*\.[A-Za-z0-9\-\.]*)\$/", $email, $matches );
      if ( strlen($matches[2]) == 0 ) {
         return false;
      }
      // valid domain & mail exchanger test
      if ( is_executable("/usr/bin/host") ) {
         $handle = popen( "/usr/bin/host -t mx " . escapeshellarg($matches[2]) . " 2>&1", 'r' );
         $output = fread($handle, 1024);
         pclose($handle);
         if ( preg_match("/Host .* not found: \d+\(\w+\)/", $output) ) {
            return false;
         }
      }
      // default case
      return true;
   }
    



   /**
    * Hooks the menu into the admin screen
    *  
    */
   function spamkit_action_admin_menu() {
      if ( function_exists('add_options_page') )
            add_options_page( __("SpamKit Options Page"), __('SpamKit'), 7, basename(__FILE__) );
   }
 
 
 
 
   /*
    *  Add the Hooks into Wordpress
    *
    */

   if ( function_exists("add_action") ) {
      add_action('comment_form',          'spamkit_action_comment_form');
      add_action('pre_comment_approved',  'spamkit_action_pre_comment_approved' );
      add_action('comment_post',          'spamkit_action_comment_post', 0, 2 );
      add_action('wp_set_comment_status', 'spamkit_action_set_comment_status', 0, 2 );
      add_action('admin_menu',            'spamkit_action_admin_menu');
   }
   
   
   
   
   /*
    *  Installation ping-back
    *
    */
    
   if(((isset($_GET['action'])) && ($_GET['action']=="deactivate")) && ((isset($_GET['plugin'])) && ($_GET['plugin']=="spamkit-plugin.php"))) {
      // Plugin deactivated
   } else if(((isset($_GET['action'])) && ($_GET['action']=="activate")) && ((isset($_GET['plugin'])) && ($_GET['plugin']=="spamkit-plugin.php"))) {
      // Plugin activated
      spamkit_ping_activate();
   }

}
/* spamkit-plugin.php :: bottom */
?>
<?php
/* spamkit-admin.php :: top
 * 
 * (c) 2005-2006 Michael Cutler (m@cotdp.com)
 *
 * You may distribute under any terms and any license of
 * your choosing providing you credit the author for his work.
 * 
 */

   if ( basename($_SERVER['SCRIPT_NAME']) == "options-general.php" && $_GET['page'] == "spamkit-plugin.php" ) {
      if ( ! defined("spamkit-admin.php") ) {
         // executed before any content has been returned to the browser, useful for catching forms and redirecting
         define( "spamkit-admin.php", true );
         
         if ( $_POST['action'] == "update" && !empty($_POST['page_options']) ) {
            $page_options = split( ",", $_POST['page_options'] );
            foreach ( $page_options as $key ) {
               $spamkit_options[$key] = $_POST[$key] ? $_POST[$key] : 0;
            }
            define( "spamkit_options_saved", true );
            update_option("spamkit_options", $spamkit_options);
         }
         
      } else {
         // executed to produce the form HTML
         
         if ( defined( "spamkit_options_saved" ) )
            echo "<div id='message' class='updated fade'><p><strong>Options saved.</strong></p></div>\n\n";
         
         ?>
         <div class="wrap">
            <h2>SpamKit Configuration</h2>
            <br /><?php _e("This is the configuration page for the SpamKit plugin."); ?><br />
            <br />
            <fieldset name="spamkit_fieldset">
               <form method="post">
                  
                  <table width="100%" cellspacing="2" cellpadding="5" class="editform"> 
                  <tr valign="top"> 
                  <th width="33%" scope="row">Minimum Timeout:</th> 
                  <td><input type="text" name="minimum_timeout" size="4" value="<?= $spamkit_options['minimum_timeout'] ?>"  /> seconds<br />
                  Any comments submitted before this minimum timeout will be considered invalid and held for moderation. Prevents automated scripts from posting a TBT immediately. This should normally be between 5 and 15 seconds.</td> 
                  </tr>
                  </table> 

                  <table width="100%" cellspacing="2" cellpadding="5" class="editform"> 
                  <tr valign="top"> 
                  <th width="33%" scope="row">Maximum Timeout:</th> 
                  <td><input type="text" name="maximum_timeout" size="4" value="<?= $spamkit_options['maximum_timeout'] ?>"  /> seconds<br />
                  Any comments submitted after this timeout will be considered invalid and held for moderation.
                  </td> 
                  </tr>
                  </table> 

                  <table width="100%" cellspacing="2" cellpadding="5" class="editform"> 
                  <tr valign="top"> 
                  <th width="33%" scope="row">&nbsp;</th> 
                  <td><label><input type="checkbox" name="moderate_tbt_fails" value="1" <?= $spamkit_options['moderate_tbt_fails'] ? "checked=\"true\"" : "" ?>  /> 
                     Moderate comments that fail Time-Based Token checks, disabling this option means comments which fail are automatically marked as spam - never to be seen again.</label>
                  </td> 
                  </tr>
                  </table> 

                  <table width="100%" cellspacing="2" cellpadding="5" class="editform"> 
                  <tr valign="top"> 
                  <th width="33%" scope="row">&nbsp;</th> 
                  <td><label><input type="checkbox" name="trust_local_host" value="1" <?= $spamkit_options['trust_local_host'] ? "checked=\"true\"" : "" ?>  /> 
                     Trust Trackbacks &amp; Pingbacks from the Local Host (other Blogs on the same server)</label>
                  </td> 
                  </tr>
                  </table> 

                  <table width="100%" cellspacing="2" cellpadding="5" class="editform"> 
                  <tr valign="top"> 
                  <th width="33%" scope="row">&nbsp;</th> 
                  <td><label><input type="checkbox" name="moderate_trackbacks" value="1" <?= $spamkit_options['moderate_trackbacks'] ? "checked=\"true\"" : "" ?>  /> 
                     Moderate trackbacks, disabling this option means trackbacks are automatically approved.</label>
                  </td> 
                  </tr>
                  </table> 

                  <table width="100%" cellspacing="2" cellpadding="5" class="editform"> 
                  <tr valign="top"> 
                  <th width="33%" scope="row">&nbsp;</th> 
                  <td><label><input type="checkbox" name="enable_badge" value="1" <?= $spamkit_options['enable_badge'] ? "checked=\"true\"" : "" ?>  /> 
                     Enable Badge functionality, you may choose to disable this to avoid problems with SpamKit using /tmp files for the count.</label>
                  </td> 
                  </tr>
                  </table> 

                  <table width="100%" cellspacing="2" cellpadding="5" class="editform"> 
                  <tr valign="top"> 
                  <th width="33%" scope="row">&nbsp;</th> 
                  <td><label><input type="checkbox" name="experimental" value="1" <?= $spamkit_options['experimental'] ? "checked=\"true\"" : "" ?>  /> 
                     Enable <strong>EXPERIMENTAL</strong> Functionality including:<br/>
                     - Comments posted with User-Agent header are ignored and not held for moderation.<br/>
                     - If the provided email address is not well-formed (something)@(something).(something) or has no Mail Exchanger DNS record, the comment is ignored and not held for moderation.
                  </label>
                  </td> 
                  </tr>
                  </table> 
                  
                  <p class="submit">
                     <input type="hidden" name="action" value="update" /> 
                     <input type="hidden" name="page_options" value="experimental,minimum_timeout,maximum_timeout,trust_local_host,enable_badge,moderate_tbt_fails,moderate_trackbacks" /> 
                     <input type="submit" name="Submit" value="Update Options &raquo;" /> 
                  </p>
               </form>
            </fieldset>
            <br />
         </div><br />&nbsp;
         <?
      }
   }
   
/* spamkit-admin.php :: bottom */
?>
<?php
/* spamkit-badge.php :: top
 * 
 * (c) 2005-2006 Michael Cutler (m@cotdp.com)
 *
 * You may distribute under any terms and any license of
 * your choosing providing you credit the author for his work.
 * 
 */
if( ! defined("spamkit-badge.php") ) {
   
   define( "spamkit-badge.php", true );
 
 
 
   /**
    * Generates HTML code to display the spamkit badge
    * 
    * Usage:
    * 
    * <?php
    * if ( function_exists("spamkit_badge") ) {
    *    spamkit_badge();
    * }
    * ?>
    * 
    */
   function spamkit_badge( $return = false ) {
      $count = spamkit_get_count();
      $baseurl = "";
      if ( function_exists("get_bloginfo") )
         $baseurl = get_bloginfo('siteurl');
      $title = "SpamKit Plugin for Wordpress: Caught " . $count . " Spam Comments!";
      $html = "<a href='http://blog.lobstertechnology.com/category/wordpress/plugins/spamkit/' title='$title'><img src='$baseurl/wp-content/plugins/spamkit-plugin.php' width='80' height='15' alt='$title'/></a>";
      if ( $return ) {
         return $html;
      } else {
         echo $html;
      }
   }
 
 
 
 
   /**
    * 
    * @return count from file
    */
   function spamkit_get_count( ) {
      $count = 0;
      $name = sprintf('%08X', crc32($_SERVER['SERVER_NAME']));
      if ( file_exists("/tmp/spamkit-" . $name) ) {
         $handle = fopen( "/tmp/spamkit-" . $name, "r" );
         if ( $handle ) {
            $content = '';
            while ( !feof($handle) ) {
               $content .= fread( $handle, 1024 );
            }
            fclose( $handle );
            $content = preg_replace( "/[\D]/", "", $content );
            $count = intval( $content );
         }
      }
      return $count;
   }
 
 
 
   // Hook only direct calls to this script
   if ( basename($_SERVER['SCRIPT_NAME']) == "spamkit-plugin.php" ) {
      
       $now = time();
       
       // Test the If-Modified-Since header (if set)
       if ( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) {
           $if_modified_since = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
           if ( $if_modified_since > ($now - 60) ) {
               header( "HTTP/1.0 304 Not Modified" );
               exit;
           }
       }
      
      $count = spamkit_get_count();
      
      // Create image of the default badge dimensions (80x15px)
      $im = imagecreate( 80, 15 );
      
      // Sanity check, read the width & height back from the image
      $width  = imagesx($im);
      $height = imagesy($im);
      
      // Prepare colours
      $textcolor  = imagecolorallocate($im, 0, 0, 0);
      $textcolor2 = imagecolorallocate($im, 255, 255, 255);
      $fillcolor1 = imagecolorallocate($im, 255, 255, 255);
      $fillcolor2 = imagecolorallocate($im, 255, 85, 0);
      $black = imagecolorallocate($im, 0, 0, 0);
      $white = imagecolorallocate($im, 255, 255, 255);
      $grey  = imagecolorallocate($im, 0xe0, 0xe0, 0xe0);
      $metercolor   = imagecolorallocate($im, 0, 240, 0);
      $bordercolor  = imagecolorallocate($im, 0, 0, 0);
      $bordercolor2 = imagecolorallocate($im, 40, 40, 40);
      
      // Calculate font width's
      $font = 1;
      $textWidth = imagefontwidth($font);
      $textHeight = imagefontheight($font);
   
      // Fill the entire image with black
      imagefilledrectangle ( $im, 0, 0, $width, $height, $black );
      // Fill the inner area with white leaving an outer black 'border'
      imagefilledrectangle ( $im, 1, 1, $width - 2, $height - 2, $white );
      // Fill the title box
      imagefilledrectangle ( $im, 2, 2, 40, 12, $fillcolor2 );
      // Fill the info box
      imagefilledrectangle ( $im, 42, 2, $width - 3, 12, $grey );
      // Draw title text
      imagestring($im, $font, 4, 3, "SPAMKIT", $textcolor2);
      // Draw info text
      imagestring($im, $font, 43, 3, $count, $textcolor);
     
      
      // Set headers
      header("Expires: " . gmdate("D, d M Y H:i:s", $now + 60 ) . " GMT"); // expires in 1 minute
      header("Last-Modified: " . gmdate("D, d M Y H:i:s", $now) . " GMT");
   
      
      // Output image to browser
      if ( function_exists('imagegif') ) {
         header("Content-type: image/gif");
         imagegif($im);
      } else if ( function_exists('imagepng') ) {
         header("Content-type: image/png");
         imagepng($im);
      } else if ( function_exists('imagejpeg') ) {
         header("Content-type: image/jpeg");
         imagejpeg($im);
      }
   
      // Destroy the image
      imagedestroy($im);
      
      exit;       
   }

}
/* spamkit-badge.php :: bottom */
?>
<?php //-- Begin: spamkit_token.php --//
if( ! defined("spamkit_token.php") ) {
   
   define( "spamkit_token.php", true );
 

/**
*  SpamKit - Time Tokens
*  Part of Gerry's PHP Spam Kit for GuestBooks and Forums.
*  By Gerard Calderhead (Gerry@EverythingSucks.co.uk)
*
*  You may distribute under any terms and any license of
*  your choosing providing you credit the author(s) of this
*  sourcefile for their work.
*
*  INFORMATION:
*  For updates and to read the few blog articles I actually post
*  about this stuff visit
*
*           http://webofshite.com/?cat=4
*
*  USEAGE:
*  See in-line documentation.
*
*  AUTHORS:
*  --------
*  Gerard Calderhead    (gerry@everythingsucks.co.uk>
*  Michael Cutler       (m@cotdp.com)
*
*  CHANGE LOG:
*  -----------
*  2006-02-05   Gerry   Added source IP to TBT to stop "zombie nets" getting
*                       through.  Some weird tricks are afoot.  Michael spotted
*                       these this week, expect a blog entry soon with some
*                       analysis at http://blog.lobstertechnology.com [v1.2]
*  2006-02-28   Gerry   Changed to a class.  Got rid of the DEFINEs you had to
*                       add to your application and shoved the neccessary state
*                       into the constructor.  Removed mcrypt since a LOT of
*                       sysadmins seems to build PHP without it.  Stubbed out
*                       implementation of RC4.
*  2006-03-01   Gerry   Added proper RC4 implementation by Michael. [v1.3]
*
*/
class TimeBasedToken {
  var $last_token = "";
  var $last_stamp = 0;
  var $last_crc   = 0;
  var $last_ip    = "";
  var $key        = "";

  /**
   *  Construct a TBT object.
   *  @param $key is the password to encrypt with.
   */
  function TimeBasedToken( $key ) {
    // In the case of the the SpamKit wordpress plugin the password
    // used is derived from wordpress DB password.  It makes sense
    // to run this through sha1 to protect against uncovering the
    // password using known-plaintext.
    // Perhaps I'm being a little over-cautious :D
    $this->key = sha1( $key );
  }

  /**
   * Generate a Time Token which can be used to control access to a specific
   * area of functionality etc.
   * @param $offset is an offset in seconds (+ve or -ve) for the token you generate. [Optional]
   * @return string token value used to limit access to a given time.
   */
  function generateToken( $offset = 0 )
  {
    $this->last_stamp = time( ) + $offset;
    $t                = dechex( $this->last_stamp );
    $this->last_ip    = $_SERVER['REMOTE_ADDR'];
    $this->last_crc   = crc32($t . $this->last_ip);
    $c                = dechex( $this->last_crc );
    $this->last_token = urlencode(base64_encode($this->rc4($this->key, $t . "|" . $this->last_ip . "|" . $c )));
    return $this->last_token;
  }

  /**
   *  Get the TBT string for the current token.
   *  @return string version of the current TBT.
   */
  function getToken( ) {
    return $this->last_token;
  }

  /**
   *  Change the current TBT value we are working with for this
   *  TimeBasedToken object.
   *  @param $token is the new TBT value
   */
  function setToken( $token ) {
    $this->token_parse( $token );
  }

  /**
   *  Checks to see if the supplied Time Token is of the correct format and that its
   *  internal checksum computes to the expected value.
   *  @param $token is the token you want to validate
   *  @return boolean the result of the validation
   */
  function isValid( )
  {
    if ( crc32( dechex($this->last_stamp) . $this->last_ip ) !=$this->last_crc ) return FALSE;
    if ( strcmp( $this->last_ip, $_SERVER['REMOTE_ADDR'] ) ) return FALSE;
    return TRUE;
  }

  /**
   * Check whether a Time Token is older than the given number of seconds.
   * @param $tmo_secs the Time Out (seconds) you want to check the token against.
   * @return boolean result indicating if the token has timed out.
   */
  function checkTimeout( $tmo_secs )
  {
    return (($this->last_stamp + intval($tmo_secs) <= time() )===TRUE);  
  }

  /**
   * Read the unix timestamp out of the supplied token.
   * @return int of unix timestamp from token
   */
  function getStamp( )
  {
    return $this->last_stamp;
  }

  /**
   * Read the security hash from the supplied token
   * @return string with the Hash/Security variable from token.
   */
  function getHash( )
  {
    return $this->last_crc;
  }

  /**
   * IP of the request is now part of the token to get around
   * what seems to be distributed attacks.   Will add a link to
   * Michael's blog if he ever writes up an article about this.
   * @return string with the IP address of the original request
   *
   */
  function getIP( )
  {
    return $this->last_ip;
  }
  
  /**
   *  Decrypt and parse out a TBT value into it's component parts and update
   *  out local globals with the values.  This makes the accessor methods for
   *  the TBTs a little faster as we're not constantly decrypting and parsing em.
   *  @param $token is the TBT value to decrypt and parse out.
   */
  function token_parse( $token ) {
    if ( strcmp( $token, $this->last_token) == 0 ) return;
    $this->last_token = $token;
    $data = $this->rc4( $this->key, base64_decode(urldecode($token)) );
    $data = split( "\|", $data );
    $this->last_stamp = intval( hexdec( @$data[0] ));
    $this->last_ip    = @$data[1];
    $this->last_crc   = intval(hexdec( @$data[2] ));
  }

  /**
   * A PHP implementation of RC4 based on the original C code from
   * the 1994 usenet post:
   *
   * http://groups.google.com/groups?selm=sternCvKL4B.Hyy@netcom.com
   *
   * @param key_str the key as a binary string
   * @param data_str the data to decrypt/encrypt as a binary string
   * @return the result of the RC4 as a binary string
   * @author Michael Cutler <m@cotdp.com>
   */
   function rc4( $key_str, $data_str ) {
      // convert input string(s) to array(s)
      $key = array();
      $data = array();
      for ( $i = 0; $i < strlen($key_str); $i++ ) {
         $key[] = ord($key_str{$i});
      }
      for ( $i = 0; $i < strlen($data_str); $i++ ) {
         $data[] = ord($data_str{$i});
      }
      // prepare key
      $state = array( 0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,
                      16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,
                      32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,
                      48,49,50,51,52,53,54,55,56,57,58,59,60,61,62,63,
                      64,65,66,67,68,69,70,71,72,73,74,75,76,77,78,79,
                      80,81,82,83,84,85,86,87,88,89,90,91,92,93,94,95,
                      96,97,98,99,100,101,102,103,104,105,106,107,108,109,110,111,
                      112,113,114,115,116,117,118,119,120,121,122,123,124,125,126,127,
                      128,129,130,131,132,133,134,135,136,137,138,139,140,141,142,143,
                      144,145,146,147,148,149,150,151,152,153,154,155,156,157,158,159,
                      160,161,162,163,164,165,166,167,168,169,170,171,172,173,174,175,
                      176,177,178,179,180,181,182,183,184,185,186,187,188,189,190,191,
                      192,193,194,195,196,197,198,199,200,201,202,203,204,205,206,207,
                      208,209,210,211,212,213,214,215,216,217,218,219,220,221,222,223,
                      224,225,226,227,228,229,230,231,232,233,234,235,236,237,238,239,
                      240,241,242,243,244,245,246,247,248,249,250,251,252,253,254,255 );
      $len = count($key);
      $index1 = $index2 = 0;
      for( $counter = 0; $counter < 256; $counter++ ){
         $index2   = ( $key[$index1] + $state[$counter] + $index2 ) % 256;
         $tmp = $state[$counter];
         $state[$counter] = $state[$index2];
         $state[$index2] = $tmp;
         $index1 = ($index1 + 1) % $len;
      }
      // rc4
      $len = count($data);
      $x = $y = 0;
      for ($counter = 0; $counter < $len; $counter++) {
         $x = ($x + 1) % 256;
         $y = ($state[$x] + $y) % 256;
         $tmp = $state[$x];
         $state[$x] = $state[$y];
         $state[$y] = $tmp;
         $data[$counter] ^= $state[($state[$x] + $state[$y]) % 256];
      }
      // convert output back to a string
      $data_str = "";
      for ( $i = 0; $i < $len; $i++ ) {
         $data_str .= chr($data[$i]);
      }
      return $data_str;
   }
}

//-- End: spamkit_token.php --//
}
?>
