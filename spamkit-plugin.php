<?php
/*
Plugin Name: SpamKit Plugin
Plugin URI: http://blog.lobstertechnology.com/category/wordpress/plugins/
Description: Prototype, uses <a href='http://webofshite.com/?p=3'>Time-Based-Tokens</a> in the comment form [by <a href='http://webofshite.com/'>Gerard Calderhead</a>]. If Wordpress recieves a comment-post without the token, or with an invalid token the comment is held for moderation. In this version, there are no option pages or any visual aspects to this plugin.
Version: 0.0
Author: Michael Cutler
Author URI: http://blog.lobstertechnology.com/
Update: http://blog.lobstertechnology.com/category/wordpress/plugins/
*/
/* spamkit-plugin.php :: top
 * 
 * (c) 2005 Michael Cutler (m@cotdp.com)
 *
 * You may distribute under any terms and any license of
 * your choosing providing you credit the author for his work.
 * 
 * $Id$
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
   function spam_action_comment_form() {
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
   function spam_action_pre_comment_approved( $approved ) {
      // fail immediately if the token is invalid
      if ( spamkit_checkTimeTokenValid( $_POST["token"] ) == FALSE )
         return "spam";
      // fail if the token is more than 15 minutes old
      if ( spamkit_checkTimeTokenTimeout( $_POST["token"], 900 ) == TRUE )
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
   function spam_action_comment_post( $comment_id, $approved ) {
      global $wpdb;
      if ( $approved == "spam" )
         $wpdb->query("UPDATE $wpdb->comments SET comment_approved = '0' WHERE comment_ID = '$comment_id';");
   }




   /*
    *  Add the Hooks into Wordpress
    *
    */

   add_action('comment_form',         'spam_action_comment_form');
   add_action('pre_comment_approved', 'spam_action_pre_comment_approved' );
   add_action('comment_post',         'spam_action_comment_post', 0, 2 );




/* spamkit-plugin.php :: bottom */
?>
<?php
/* spamkit-token.php :: top */ 
/* SpamKit - Time Tokens (www.webofshite.com)
 * Part of Gerrys PHP Spam Kit for GuestBooks and Forums.
 * http://webofshite.com/downloads/blogspam/examples/example1.php
 * 
 * (c) 2005 Gerard Calderhead (Gerry@EverythingSucks.co.uk)
 *
 * You may distribute under any terms and any license of
 * your choosing providing you credit the author for his work.
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
 * NOTE: DES keys demand a CRYPT_KEY of 8 characters, no more no less
 *
 * Thats all the explaining you should need, see inline documentation.
 */
   
   $__spamkit_last_token = "";
   $__spamkit_last_stamp = 0;
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
   $__spamkit_last_stamp = time( ) + $offset;
   $t          = dechex( $__spamkit_last_stamp );
   $__spamkit_last_crc   = crc32($t);
   $c          = dechex( $__spamkit_last_crc );
   $__spamkit_last_token = urlencode(base64_encode(__spamkit_tok_do_encrypt( $t . "." . $c )));
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
     __spamkit_tok_parse( $token );
     if ( crc32( dechex($__spamkit_last_stamp)) !=$__spamkit_last_crc ) return FALSE;
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
     if ( strcmp( $token, $__spamkit_last_token) == 0 ) return;
     $__spamkit_last_token = $token;
     $data = __spamkit_tok_do_decrypt( base64_decode(urldecode($token)) );
     $data = split( "\.", $data );
     $__spamkit_last_stamp = intval( hexdec( $data[0] ));
     $__spamkit_last_crc   = intval(hexdec( $data[1] ));
   }

/* spamkit-token.php :: bottom */ 
?>
