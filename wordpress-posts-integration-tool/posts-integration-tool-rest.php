<?php
  namespace Metatavu\WpPostsIntegrationTool;
  
  require_once( __DIR__ . '/../vendor/autoload.php');
  
  if (!defined('ABSPATH')) { 
    exit;
  }
  
  if (!class_exists( 'Metatavu\WpPostsIntegrationTool\Rest' ) ) {
    
    class Rest {
      
      public function __construct() {
        register_rest_field('post', 'metadata', [
          'get_callback' => function ($data) {
            return get_post_meta($data['id'], '', '');
          }
        ]);

        add_filter('rest_prepare_post', function ($data, $post, $context) {
          $featuredImageId = $data->data['featured_media']; 
          $featuredImageUrl = wp_get_attachment_image_src( $featuredImageId, 'original' );
  
          if ($featuredImageUrl) {
            $data->data['featured_image_url'] = $featuredImageUrl[0];
          }
  
          return $data;
      }, 10, 3);
      }

    }
  }
  
  add_action('rest_api_init', function () {
    new Rest();
  });
  
?>