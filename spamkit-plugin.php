<?php
/*
Plugin Name: SpamKit Plugin
Plugin URI: http://blog.lobstertechnology.com/category/wordpress/plugins/spamkit/
Description: Prototype, uses <a href='http://webofshite.com/?p=3'>Time-Based-Tokens</a> in the comment form [by <a href='http://webofshite.com/'>Gerard Calderhead</a>]. If Wordpress receives a comment-post without the token, or with an invalid token the comment is held for moderation. In this version, there are no option pages or any visual aspects to this plugin.
Version: 0.3
Author: Michael Cutler
Author URI: http://blog.lobstertechnology.com/
Update: http://blog.lobstertechnology.com/category/wordpress/plugins/spamkit/
*/
/*
 * CHANGELOG:
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
 * 14/02/2006  The path generated by the spamkit_badge() method makes a reference to an absolute path (/wp-content) that is not portable across all Wordpress installations. This can be worked around by adding the HTML yourself with the correct path.
 *             I assume all systems have a /tmp filesystem which is writable by the WWW server user.
 * 
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
 
   /*
    * Prepare the key, if its not exactly 8 characters in length crop
    * or fill it by appending copies of itself.
    * 
    */
   $spamkit_key = DB_PASSWORD;
   if ( strlen($spamkit_key) < 8 ) {
      while ( strlen($spamkit_key) < 8 ) {
         $spamkit_key .= DB_PASSWORD;
      }
   }
   if ( strlen($spamkit_key) > 8 ) {
      $spamkit_key = substr( $spamkit_key, 0, 8 );
   } 


   define( SPAMKIT_TOKEN_CRYPT_KEY, $spamkit_key );
   define( SPAMKIT_TOKEN_CRYPT_METHOD, MCRYPT_DES );


   /**
    * Called by Wordpress while the comment form is being displayed, allows you to append XHTML
    * before the closing "</form>".
    *
    * Used here to insert a hidden form field with the SpamKit generated TimeToken
    *
    * @return nothing
    */
   function spamkit_action_comment_form() {
      echo "<input type='hidden' name='token' value='" . spamkit_getTimeToken(  ) . "'/>\n";
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
   	  // comment is most likely a trackback from my own blog / server
   	  // this could be abused if another web application on the server
   	  // is exploited allowing an attacker to post comments apparently
   	  // from this server
   	  //
   	  // skips time-based token checking
   	  if ( $_SERVER['REMOTE_ADDR'] == $_SERVER['SERVER_ADDR'] )
   	  	return $approved; 	  
      // fail immediately if the token is invalid
      if ( spamkit_checkTimeTokenValid( $_POST["token"] ) == FALSE )
         return "spam";
      // fail if the token is less than 5 seconds old
      if ( spamkit_checkTimeTokenTimeout( $_POST["token"], 5 ) == FALSE )
         return "spam";
      // fail if the token is more than 60 minutes old
      if ( spamkit_checkTimeTokenTimeout( $_POST["token"], 3600 ) == TRUE )
         return "spam";
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
      global $wpdb;
      if ( $approved == "spam" ) {
         // Update the comment
         $wpdb->query("UPDATE $wpdb->comments SET comment_approved = '0' WHERE comment_ID = '$comment_id';");
      }
      
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
      $client->query('plugin_manager.ping', "spamkit-plugin", "0.3", get_settings('blogname'), $home);
   }




   /*
    *  Add the Hooks into Wordpress
    *
    */

   if ( function_exists("add_action") ) {
      add_action('comment_form',         'spamkit_action_comment_form');
      add_action('pre_comment_approved', 'spamkit_action_pre_comment_approved' );
      add_action('comment_post',         'spamkit_action_comment_post', 0, 2 );
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


/* spamkit-plugin.php :: bottom */
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
      $title = "SpamKit Plugin for Wordpress: Caught " . $count . " Spam Comments!";
      $html = "<a href='http://blog.lobstertechnology.com/category/wordpress/plugins/spamkit/' title='$title'><img src='/wp-content/plugins/spamkit-plugin.php' width='80' height='15' alt='$title'/></a>";
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
      
      // Draw meter bar
      if ( $loadav ) {
         $limit = 20;   //this is the upper limit of the range of server load values... adjust to your server
         $percent = ($loadav * 100) /$limit;
         $meterwidth = ($percent / 100) * 28;
         if ($meterwidth > $limit)
          $meterwidth = $limit;
         imagefilledrectangle ( $im, 48, 4, 48 + $meterwidth, $height-6, $metercolor );
         imagerectangle ( $im, 48, 4, 76, $height-6, $bordercolor2 );
      } else {
         
      }
      
      
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

/* spamkit-badge.php :: bottom */
?>
<?php
/* spamkit-token.php :: top */ 
/* SpamKit - Time Tokens
* Part of Gerry's PHP Spam Kit for GuestBooks and Forums.
* By Gerard Calderhead (Gerry@EverythingSucks.co.uk)
*  
* You may distribute under any terms and any license of
* your choosing providing you credit the author for his work.
*
* INFORMATION:
* For updates and to read the few blog articles I actually post
* about this stuff visit
*
*           http://webofshite.com/?cat=4
*
* USEAGE:
*
* At an appropriate point in your PHP code do the following
*
*     define(SPAMKIT_TOKEN_CRYPT_KEY,"password");
*     define(SPAMKIT_TOKEN_CRYPT_METHOD, MCRYPT_DES );
*
* Remebering to change your password and choosing a crypto method
* appropriate to your needs ( DES should be fine generally ).
*
* That's all the explaining you should need, see inline documentation.
*
* CHANGE LOG:
* -----------
* 2006-02-05     Gerry    Added source IP to TBT to stop "zombie nets" getting
*                      through.  Some weird tricks are afoot.  Michael spotted
*             these this week, expect a blog entry soon with some
*            analysis at http://blog.lobstertechnology.com
*
*/

$__spamkit_last_token = "";
$__spamkit_last_stamp = 0;
$__spamkit_last_ip    = "";
$__spamkit_last_crc   = 0;

/**
* Generate a Time Token which can be used to control access to a specific
* area of functionality etc.
* @param $offset is an offset in seconds (+ve or -ve) for the token you generate. [Optional]
* @return string token value used to limit access to a given time.
*/
function spamkit_getTimeToken( $offset = 0 )
{
global $__spamkit_last_token;
global $__spamkit_last_stamp;
global $__spamkit_last_crc;
global $__spamkit_last_ip;
$__spamkit_last_stamp = time( ) + $offset;
$t          = dechex( $__spamkit_last_stamp );
$__spamkit_last_ip    = $_SERVER['REMOTE_ADDR'];
$__spamkit_last_crc   = crc32($t . $__spamkit_last_ip );
$c          = dechex( $__spamkit_last_crc );
$__spamkit_last_token = urlencode(base64_encode(__spamkit_tok_do_encrypt( $t . "|" . $__spamkit_last_ip . "|" . $c )));
return $__spamkit_last_token;
}


/**
*  Checks to see if the supplied Time Token is of the correct format and that its
*  internal checksum computes to the expected value.
*  @param $token is the token you want to validate
*  @return boolean the result of the validation
*/
function spamkit_checkTimeTokenValid( $token )
{
  global $__spamkit_last_stamp;
  global $__spamkit_last_crc;
  global $__spamkit_last_ip;
  __spamkit_tok_parse( $token );
  if ( crc32( dechex($__spamkit_last_stamp ) . $__spamkit_last_ip) !=$__spamkit_last_crc ) return FALSE;
  if ( strcmp( $__spamkit_last_ip, $_SERVER["REMOTE_ADDR"] ) ) return FALSE;
  return TRUE;
}

/**
* Check whether a Time Token is older than the given number of seconds.
* @param $token the Time Token whose age you wish to check
* @param $tmo_secs the Time Out (seconds) you want to check the token against.
* @return boolean result indicating if the token has timed out.
*/
function spamkit_checkTimeTokenTimeout( $token, $tmo_secs )
{
  global $__spamkit_last_stamp;
  __spamkit_tok_parse( $token );
  return (($__spamkit_last_stamp + intval($tmo_secs) <= time() )===TRUE);  
}

/**
* Read the unix timestamp out of the supplied token.
* @param $token is a TBT value supplied as a string.
* @return int of unix timestamp from token
*/
function spamkit_getTimeTokenStamp( $token )
{
  global $__spamkit_last_stamp;
  __spamkit_tok_parse( $token );
  return $__spamkit_last_stamp;
}

/**
* Read the security hash from the supplied token
* @param $token is TBT value supplied as a string.
* @return string with the Hash/Security variable from token.
*/
function spamkit_getTimeTokenHash( $token )
{
  global $__spamkit_last_crc;
  __spamkit_tok_parse( $token );
  return $__spamkit_last_crc;
}

/**
* IP of the request is now part of the token to get around
* what seems to be distributed attacks.   Will add a link to
* Michael's blog if he ever writes up an article about this.
* @param $token is TBT value supplied as a string.
* @return string with the IP address of the original request
*
*/
function spamkit_getTimeTokenIP( $token)
{
  global $__spamkit_last_ip;
  __spamkit_tok_parse( $token );
  return $__spamkit_last_ip;
}



/*********************
* Private Functions *
*********************/


/**
* Encrypt the given piece of data
* @param $data is the item to be encrypted
* @retun string containing the encrypted dats
*/
function __spamkit_tok_do_encrypt( $data )
{
  return mcrypt_encrypt(SPAMKIT_TOKEN_CRYPT_METHOD, SPAMKIT_TOKEN_CRYPT_KEY, $data, MCRYPT_MODE_CBC, __spamkit_tok_get_iv() );
}


/**
* Decrypt the given piece of data.
* @param $data is the item to be decrypted
* @return string containing the decrypted data
*/
function __spamkit_tok_do_decrypt( $data )
{
  return mcrypt_decrypt(SPAMKIT_TOKEN_CRYPT_METHOD, SPAMKIT_TOKEN_CRYPT_KEY, $data, MCRYPT_MODE_CBC, __spamkit_tok_get_iv() );
}


/**
* Generate an initialisation vector for the crypto engine we're using,
* we don't care too much about this so just replicate parts of the password
* for use here to keep the config simple.
*
* @return string value used to initialise crypto engines.
*/
function __spamkit_tok_get_iv( ) {
  $iv_size = mcrypt_get_iv_size(SPAMKIT_TOKEN_CRYPT_METHOD, MCRYPT_MODE_ECB);
  $iv = "";
  $x  = 0;
  $y  = 0;
  $e  = SPAMKIT_TOKEN_CRYPT_KEY;
  $l  = strlen( $e );
  for ( $x=0; $x<$iv_size; $x++ ) {
    $iv = $iv . $e[$y];
    $y = ($y+1) % $l;
  }
  return $iv;
}


/**
*  Decrypt and parse out a TBT value into it's component parts and update
*  out local globals with the values.  This makes the accessor methods for
*  the TBTs a little faster as we're not constantly decrypting and parsing em.
*  @param $token is the TBT value to decrypt and parse out.
*/
function __spamkit_tok_parse( $token ) {
  global $__spamkit_last_token;
  global $__spamkit_last_stamp;
  global $__spamkit_last_crc;
  global $__spamkit_last_ip;
  if ( strcmp( $token, $__spamkit_last_token) == 0 ) return;
  $__spamkit_last_token = $token;
  $data = __spamkit_tok_do_decrypt( base64_decode(urldecode($token)) );
  $data = split( "\|", $data );
  $__spamkit_last_stamp = intval( hexdec( $data[0] ));
  $__spamkit_last_ip    = $data[1];
  $__spamkit_last_crc   = intval(hexdec( $data[2] ));
}

/* spamkit-token.php :: bottom */ 
?>
