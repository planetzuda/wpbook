<?php
/*
 * This function updates profile boxes and publishes to Facebook
 * In an include to avoid PHP4 based errors
 */
function wpbook_safe_publish_to_facebook($post_ID) {  
  global $current_user;
  get_currentuserinfo();
  
  if(!class_exists('FacebookRestClient')) {
    include_once(WP_PLUGIN_DIR.'/wpbook/includes/client/facebook.php');
  }           
	$wpbookOptions = get_option('wpbookAdminOptions');
  
	if (!empty($wpbookOptions)) {
		foreach ($wpbookOptions as $key => $option)
		$wpbookAdminOptions[$key] = $option;
	}
	
	$api_key = $wpbookAdminOptions['fb_api_key'];
	$secret  = $wpbookAdminOptions['fb_secret'];
  $target_admin = $wpbookAdminOptions['fb_admin_target'];
  $target_page = $wpbookAdminOptions['fb_page_target'];
  $stream_publish = $wpbookAdminOptions['stream_publish'];
  $stream_publish_pages = $wpbookAdminOptions['stream_publish_pages'];
  $wpbook_show_errors = $wpbookAdminOptions['show_errors'];
  $wpbook_promote_external = $wpbookAdminOptions['promote_external'];
  $wpbook_attribution_line = $wpbookAdminOptions['attribution_line'];
  $wpbook_as_note = $wpbookAdminOptions['wpbook_as_note'];

	Facebook::$CURL_OPTS[CURLOPT_SSL_VERIFYPEER] = false;
  Facebook::$CURL_OPTS[CURLOPT_SSL_VERIFYHOST] = 2;
  
  
  $facebook = new Facebook($api_key, $secret);
  $access_token = get_option('wpbook_user_access_token');
  
  if($access_token == '') {
    if(DEBUG) {
      $fp = fopen($debug_file, 'a');
      $debug_string=date("Y-m-d H:i:s",time())." : No access token\n";
      fwrite($fp, $debug_string);
    }
  }
  if((!empty($api_key)) && (!empty($secret)) && (!empty($target_admin)) && (($stream_publish == "true") || $stream_publish_pages == "true")) {
    // here we should also post to the author's stream
    $my_post = get_post($post_ID);
    if(!empty($my_post->post_password)) { // post is password protected, don't post
      return;
    }
    if(get_post_type($my_post->ID) != 'post') { // only do this for posts
      return;
    }
    $publish_meta = get_post_meta($my_post->ID,'wpbook_fb_publish',true); 
    if(($publish_meta == 'no')) { // user chose not to post this one
      return;
    }
    $my_title=$my_post->post_title;
    $my_author=get_userdata($my_post->post_author)->display_name;
    if($wpbook_promote_external) { 
      $my_permalink = get_permalink($post_ID);
    } else {
      $my_permalink = wpbook_always_filter_postlink(get_permalink($post_ID));
    }
    $message = wpbook_attribution_line($wpbook_attribution_line,$my_author);
  
    if(($my_post->post_excerpt) && ($my_post->post_excerpt != '')) {
      $wpbook_description = stripslashes(wp_filter_nohtml_kses(apply_filters('the_content',$my_post->post_excerpt)));
    }
    else { 
      $wpbook_description = stripslashes(wp_filter_nohtml_kses(apply_filters('the_content',$my_post->post_content)));
    }
    if(strlen($wpbook_description) >= 995) {
      $space_index = strrpos(substr($wpbook_description, 0, 995), ' ');
      $short_desc = substr($wpbook_description, 0, $space_index);
      $short_desc .= '...';
      $wpbook_description = $short_desc;
    }
    $debug_file= WP_PLUGIN_DIR .'/wpbook/wpbook_pub_debug.txt';

    
    if (function_exists('get_the_post_thumbnail') && has_post_thumbnail($my_post->ID)) {
      if(DEBUG) {
        $fp = fopen($debug_file, 'a');
        $debug_string=date("Y-m-d H:i:s",time())." : function exists, and this post has_post_thumbnail - post_Id is ". $my_post->ID ." \n";
        fwrite($fp, $debug_string);
      }      
      $my_thumb_id = get_post_thumbnail_id($my_post->ID);
      if(DEBUG) {
        $fp = fopen($debug_file, 'a');
        $debug_string=date("Y-m-d H:i:s",time())." : my_thumb_id is ". $my_thumb_id ." \n";
        fwrite($fp, $debug_string);
      }
      $my_thumb_array = wp_get_attachment_image_src($my_thumb_id);
      $my_image = $my_thumb_array[0]; // this should be the url
      if(DEBUG) {
        $fp = fopen($debug_file, 'a');
        $debug_string=date("Y-m-d H:i:s",time())." : my_image is ". $my_image ." \n";
        fwrite($fp, $debug_string);
      }
    } else {
      if(DEBUG) {
        $fp = fopen($debug_file, 'a');
        $debug_string=date("Y-m-d H:i:s",time())." : Function does not exist, or no thumb \n";
        fwrite($fp, $debug_string);
      }  
      $my_image = '';
    }

    if(DEBUG) {
      $fp = fopen($debug_file, 'a');
      $debug_string=date("Y-m-d H:i:s",time())." : Post thumbail is ". $my_image ."\n";
      fwrite($fp, $debug_string);
    }
    
    if($stream_publish == "true") {
      if(DEBUG) {
        $fp = fopen($debug_file, 'a');
        $debug_string=date("Y-m-d H:i:s",time())." : Publish to Facebook Running\n";
        fwrite($fp, $debug_string);
      }
      
      $fb_response = '';
      try{
        // need new format for SDK API
        if($wpbook_as_note) {
          /* notes on walls don't allow much */ 
          $allowedtags = array('img'=>array('src'=>array(), 'style'=>array()), 
                               'span'=>array('id'=>array(), 'style'=>array()), 
                               'a'=>array('href'=>array()), 'p'=>array(),
                               'b'=>array(),'i'=>array(),'u'=>array(),'big'=>array(),
                               'small'=>array(), 'ul' => array(), 'li'=>array(),
                               'ol'=> array(), 'blockquote'=> array(),'h1'=>array(),
                               'h2'=> array(), 'h3'=>array(),
                               );
          if(!empty($my_image)) {
            /* message, picture, link, name, caption, description, source */      
            $attachment = array( 
                                'access_token' => $access_token,
                                'subject' => $my_title,
                                'link' => $my_permalink,
                                'message' => wp_kses(stripslashes(apply_filters('the_content',$my_post->post_content)),$allowedtags),  
                                'picture' => $my_image, 
                                ); 
          } else {
            $attachment = array( 
                                'access_token' => $access_token,
                                'subject' => $my_title,
                                'link' => $my_permalink,
                                'message' => wp_kses(stripslashes(apply_filters('the_content',$my_post->post_content)),$allowedtags),  
                                ); 
          }
          if(DEBUG) {
            $fp = fopen($debug_file, 'a');
            $debug_string=date("Y-m-d H:i:s",time())." : Publishing as note, $my_image is " . $my_image ." \n";
            fwrite($fp, $debug_string);
          }
          $fb_response = $facebook->api('/'. $target_admin .'/notes', 'POST', $attachment);
        } else {
          // post as an excerpt
          if(!empty($my_image)) {
            /* message, picture, link, name, caption, description, source */      
            $attachment = array( 
                                'access_token' => $access_token,
                                'name' => $my_title,
                                'link' => $my_permalink,
                                'description' => $wpbook_description,  
                                'picture' => $my_image, 
                                ); 
          } else {
            $attachment = array( 
                                'access_token' => $access_token,
                                'name' => $my_title,
                                'link' => $my_permalink,
                                'description' => $wpbook_description,  
                                'comments_xid' => $post_ID, 
                                ); 
          }
          $action_links = array( array('text' => 'Read More',
                                       'href' => $my_permalink
                                       )
                                ); 
          if(DEBUG) {
            $fp = fopen($debug_file, 'a');
            $debug_string=date("Y-m-d H:i:s",time())." : Publishing as excerpt, $my_image is " . $my_image ." \n";
            fwrite($fp, $debug_string);
          }
          $fb_response = $facebook->api('/'. $target_admin .'/feed', 'POST', $attachment);     
        }
      } catch (FacebookApiException $e) {
        if($wpbook_show_errors) {
          $wpbook_message = 'Caught exception in stream publish for user: ' .  $e->getMessage() .'Error code: '. $e->getCode();  
          wp_die($wpbook_message,'WPBook Error');
        } // end if for show errors
      } // end try-catch
      if($fb_response != '') {
        add_post_meta($my_post->ID,'_wpbook_user_stream_id', $fb_response[id]);
        add_post_meta($my_post->ID,'_wpbook_user_stream_time',0); // no comments imported yet
      }  // end of if $response
    } // end of if stream_publish 
  
    if(($stream_publish_pages == "true") && (!empty($target_page))) {      
      // publish to page with new api
      $fb_response = '';
      $access_token = get_option('wpbook_page_access_token');
      try{
        // post as an excerpt
        if(!empty($my_image)) {
          /* message, picture, link, name, caption, description, source */      
          $attachment = array( 
                              'access_token' => $access_token,
                              'name' => $my_title,
                              'link' => $my_permalink,
                              'description' => $wpbook_description,  
                              'picture' => $my_image, 
                              ); 
        } else {
          $attachment = array( 
                              'access_token' => $access_token,
                              'name' => $my_title,
                              'link' => $my_permalink,
                              'description' => $wpbook_description,  
                              ); 
        }
        $action_links = array( array('text' => 'Read More',
                                     'href' => $my_permalink
                                     )
                              );
        if(DEBUG) {
          $fp = fopen($debug_file, 'a');
          $debug_string=date("Y-m-d H:i:s",time())." : Publishing to page, image is " . $my_image ." \n";
          fwrite($fp, $debug_string);
        }
        $fb_response = $facebook->api('/'. $target_page .'/feed/','POST', $attachment); 
      } catch (FacebookApiException $e) {
        if($wpbook_show_errors) {
          $wpbook_message = 'Caught exception in publish to page ' . $e->getMessage() . ' Error code: ' . $e->getCode();
          wp_die($wpbook_message,'WPBook Error');
        } // end if for show errors
      } // end try/catch for publish to page
      if($fb_response != '') {
        add_post_meta($my_post->ID,'_wpbook_page_stream_id',$fb_response[id]);
        add_post_meta($my_post->ID,'_wpbook_page_stream_time',0); // no comments imported
      } else {
        $wpbook_message = 'No post id returned from Facebook, $fb_response was ' . print_r($fb_response,true) . '/n';
        $wpbook_message = $wpbook_message . ' and $fb_page_type was ' . $fb_page_type;
        $wpbook_message .= ' and $wpbook_description was ' . $wpbook_description;
        $wpbook_message .= ' and $my_title was ' . $my_title;
        wp_die($wpbook_message,'WPBook Error publishing to page'); 
      }
    } // end of if stream_publish_pages is true AND target_page non-empty
  } // end for if stream_publish OR stream_publish_pages is true
} // end of wpbook_safe_publish_to_facebook
?>
