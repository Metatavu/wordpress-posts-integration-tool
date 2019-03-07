<?php
  namespace Metatavu\PostIntegrationTool;
  
  if (!defined('ABSPATH')) { 
    exit;
  }

  require_once( __DIR__ . '/settings.php');
  
  if (!class_exists('\Metatavu\PostIntegrationTool\SettingsUI') ) {

    /**
     * UI for settings
     */
    class SettingsUI {

      /**
       * Constructor
       */
      public function __construct() {
        add_action('admin_init', array($this, 'adminInit'));
        add_action('admin_menu', array($this, 'adminMenu'));
      }

      /**
       * Admin menu action. Adds admin menu page
       */
      public function adminMenu() {
        add_options_page (__( "Posts Integration Tool Settings", 'postsIntegrationTool' ), __( "Posts Integration Tool", 'postsIntegrationTool' ), 'manage_options', 'postsIntegrationTool', [$this, 'settingsPage']);
      }

      /**
       * Admin init action. Registers settings
       */
      public function adminInit() {
        register_setting('postsIntegrationTool', 'postsIntegrationTool');

        add_settings_section('wpjson', __( "WP JSON", 'postsIntegrationTool' ), null, 'postsIntegrationTool');
        $this->addFieldOption('wpjson', 'text', 'url', __( "WP JSON url", 'postsIntegrationTool'));
        $this->addFieldOption('wpjson', 'text', 'categories', __( "Categories", 'postsIntegrationTool'));
        $this->addFieldOption('wpjson', 'text', 'sourceWordpressName', __( "Source wordpress name", 'postsIntegrationTool'));
      }

      /**
       * Adds new option
       * 
       * @param string $group option group
       * @param string $type option type
       * @param string $name option name
       * @param string $title option title
       */
      private function addFieldOption($group, $type, $name, $title) {
        add_settings_field($name, $title, array($this, 'createFieldUI'), 'postsIntegrationTool', $group, [
          'name' => $name, 
          'type' => $type
        ]);
      }

      /**
       * Prints field UI
       * 
       * @param array $opts options
       */
      public function createFieldUI($opts) {
        $name = $opts['name'];
        $type = $opts['type'];
        $value = Settings::getValue($name);

        echo "<input type='$type' style='width:100%;' name='" . 'postsIntegrationTool' . "[$name]' value='$value'/>";
      }


      /**
       * Prints settings page
       */
      public function settingsPage() {
        if (!current_user_can('manage_options')) {
          wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
        }

        echo '<div class="wrap">';
        echo "<h2>" . __( "Options", 'postsIntegrationTool') . "</h2>";
        echo '<form action="options.php" method="POST">';
        settings_fields('postsIntegrationTool');
        do_settings_sections('postsIntegrationTool');
        submit_button();
        echo "</form>";
        echo "</div>";
      }
    }

  }
  
  if (is_admin()) {
    $settingsUI = new SettingsUI();
  }

?>