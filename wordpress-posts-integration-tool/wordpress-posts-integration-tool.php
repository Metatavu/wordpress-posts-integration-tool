<?php
  namespace Metatavu\WpPostsIntegrationTool;
  
  if (!defined('ABSPATH')) { 
    exit;
  }

  require_once( __DIR__ . '/wp-json-client.php');
  require_once( __DIR__ . '/../settings/settings.php');

  use Metatavu\PostIntegrationTool\WpJSONClient;
  use Metatavu\PostIntegrationTool\Settings;

  if (!class_exists('\Metatavu\PostIntegrationTool\WpPostsIntegrationTool') ) {
    
    /**
     * Class to integrate wp posts from another wordpress installation
     */
    class WpPostsIntegrationTool {

      /**
       * Constructor
       */
      public function __construct() {
        add_filter('cron_schedules', function ($schedules){
          if(!isset($schedules["15min"])){
            $schedules["15min"] = array(
              'interval' => 15*60,
              'display' => __('Once every 15 minutes'));
          }
          if(!isset($schedules["30min"])){
            $schedules["30min"] = array(
              'interval' => 30*60,
              'display' => __('Once every 30 minutes'));
          }
          if(!isset($schedules["45min"])){
            $schedules["45min"] = array(
              'interval' => 45*60,
              'display' => __('Once every 45 minutes'));
        }
          return $schedules;
        });

        $hook = 'wp_posts_integration_task';
        $currentSchedule = wp_get_schedule($hook);
        $interval = 'hourly';
        
        add_action($hook, [$this, 'loopPosts']);

        if (!empty(Settings::getValue('integrationInterval'))) {
          $interval = Settings::getValue('integrationInterval');
        }

        if ($currentSchedule != $interval) {
          wp_clear_scheduled_hook($hook);
        }

        if (!wp_next_scheduled($hook)) {
          wp_schedule_event(time(), $interval, $hook);
        }
      }

      /**
       * Loop posts
       */
      public function loopPosts() {
        require_once(ABSPATH . 'wp-admin/includes/taxonomy.php');
        require_once(ABSPATH . 'wp-admin/includes/admin.php');

        if (empty(Settings::getValue('url')) || empty(Settings::getValue('categories')) || empty(Settings::getValue('sourceWordpressName'))) {
          return;
        }
        
        if (empty(Settings::getValue('weekFilter'))) {
          $weekFilter = "1";
        } else {
          $weekFilter = Settings::getValue('weekFilter');
        }

        $categoryIds = $this->getOtherWpCategoryIds();
        $pageNumber = 1;
        $perPage = 30;
        $pagesLeft = true;

        while ($pagesLeft) {
          $posts = json_decode(WpJSONClient::listPosts([
            'categories' => join(",", $categoryIds),
            'per_page' => $perPage, 
            'page' => $pageNumber,
            'after' => date("Y-m-d\TH:i:s", strtotime(sprintf('-%s weeks', $weekFilter))),
            '_embed' => true
          ]), true);

          if ($posts['data']['status'] == 400 || empty($posts[0])) { 
            $pagesLeft = false;
          } else {
            $this->updatePosts($posts);
            $pageNumber++;
          }
        }
      }

      /**
       * Update posts
       * 
       * @param $posts posts from anoother wp installation
       */
      private function updatePosts($posts) {
        $sourceName = Settings::getValue('sourceWordpressName');

        foreach ($posts as $post) {
          if ($this->isImported($post['tags'])) {
            continue;
          }

          $postId = $post['id'];
          $postObject['post_date'] = $post['date'];
          $postObject['post_title'] = $post['title']['rendered'];
          $postObject['post_content'] = $post['content']['rendered'];
          $postObject['post_excerpt'] = $post['excerpt']['rendered'];
          $postObject['post_type'] = 'post';
          $postObject['post_status'] = 'draft';
          $imageUrl = null;

          if (count($post['_embedded']['wp:featuredmedia']) > 0) {
            $imageUrl = $post['_embedded']['wp:featuredmedia'][0]['media_details']['sizes']['full']['source_url'];
          }
          
          $importedPostMeta = $this->getImportedPostMeta($sourceName, $postId);

          if (count($importedPostMeta) > 0) {
            $existingPost = get_post($importedPostMeta[0]->post_id);

            if ($existingPost->post_status == "trash") {
              continue;
            }

            $postObject['ID'] = $importedPostMeta[0]->post_id;
            $postObject['post_status'] = $existingPost->post_status == "publish" ? "publish" : "draft";
            wp_update_post($postObject, true);
            $this->generateFeaturedImage($imageUrl, $importedPostMeta[0]->post_id);
          } else {
            $postObject['post_category'] = $categories;
            $newPostId = wp_insert_post($postObject);
            wp_set_post_tags($newPostId, 'imported_post');
            $this->generateFeaturedImage($imageUrl, $newPostId);
            update_post_meta($newPostId, 'postsIntegrationToolSource', "$sourceName:$postId");
          }
        }
      }

      /**
       * Check if post is imported
       * 
       * @param $tags tags
       * @return boolean true if imported
       */
      private function isImported($tags) {
        if (count($tags) > 0) {
          $tags = json_decode(WpJSONClient::listTags([
            'include' => join(",", $post['tags'])
          ]), true);
          
          foreach ($tags as $tag) {
            if ($tag['name'] == 'imported_post') {
              return true;
            }
          }
          return false;
        }
      }

      /**
       * Get imported postmeta
       * 
       * @param $source source
       * @param $sourceId source id
       * @return post meta object
       */
      private function getImportedPostMeta($source, $sourceId) {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM $wpdb->postmeta where meta_key = 'postsIntegrationToolSource' AND meta_value = '$source:$sourceId' ");
      }

      /**
       * Generates featured image for post
       */
      private function generateFeaturedImage($imageUrl, $postId) {
        if (empty($imageUrl)) {
          return;
        }

        $uploadDir = wp_upload_dir();
        $imageData = file_get_contents($imageUrl);
        $filename = basename($imageUrl);

        if (wp_mkdir_p($uploadDir['path'])){
          $file = $uploadDir['path'] . '/' . $filename;
        } else {
          $file = $uploadDir['basedir'] . '/' . $filename;
        }
        file_put_contents($file, $imageData);
    
        $wpFiletype = wp_check_filetype($filename, null);
        $attachment = array(
          'post_mime_type' => $wpFiletype['type'],
          'post_title' => sanitize_file_name($filename),
          'post_content' => '',
          'post_status' => 'publish'
        );
        $attachId = wp_insert_attachment($attachment, $file, $postId);
        $attachData = wp_generate_attachment_metadata($attachId, $file);
        wp_update_attachment_metadata($attachId, $attachData);
        set_post_thumbnail($postId, $attachId);
      }

      /**
       * Get category ids from the another wp installation
       */
      private function getOtherWpCategoryIds() {
        $perPage = 100;
        $pageNumber = 1;
        $categoriesLeft = true;
        $categoryIds = [];

        while ($categoriesLeft == true) {
          $allCategories = json_decode(WpJSONClient::listCategories([
            'per_page' => $perPage, 
            'page' => $pageNumber
          ]), true);

          if (!is_array($allCategories) && !is_object($allCategories)) {
            $categoriesLeft = false;
          }

          if (count($allCategories) < $perPage) {
            $categoriesLeft = false;
          }
          
          $wantedCategoryNames = array_map('strtolower', explode(",", Settings::getValue('categories')));
          
            foreach ($allCategories as $category) {
              if (in_array(strtolower($category['name']), $wantedCategoryNames)) {
                array_push($categoryIds, $category['id']);
              }
            }
          

          $pageNumber++;
        }

        return $categoryIds;
      }

    }
  }

  new WpPostsIntegrationTool();
?>
