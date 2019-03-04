<?php
  namespace Metatavu\WpPostsIntegrationTool\Type;
  
  defined ('ABSPATH' ) || die ( 'No script kiddies please!');

 
  if (!class_exists('\Metatavu\WpPostsIntegrationTool\Type')) {
  
    /**
     * Custom post type 
     */
    class Type {
      
      /**
       * Constructor
       */
      public function __construct() {
        $this->registerType();
      }

      /**
       * Registers a custom post type
       */
      public function registerType() {
        register_post_type('integration_tool', [
          'labels' => [
            'name' => 'Integration tool'
          ],
          'public' => true,
          'supports' => array(
            'editor'
          ),
        ]);
      }
    }
  }

  add_action ('admin_init', function () {
    new Type();
  });
?>