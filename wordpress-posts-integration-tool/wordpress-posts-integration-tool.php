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
        add_action('wpPostsIntegration', [$this, 'loopPosts']);
        if (!wp_next_scheduled('wpPostsIntegration')) {
          wp_schedule_event(time(), 'hourly', 'wpPostsIntegration');
        }
      }

      /**
       * Loop posts
       */
      public function loopPosts() {
        if (empty(Settings::getValue('url')) || empty(Settings::getValue('categories')) || empty(Settings::getValue('wordpressName')) || empty(Settings::getValue('sourceWordpressName'))) {
          return;
        }

        $categoryIds = $this->getOtherWpCategoryIds();
        $pageNumber = 1;
        $perPage = 30;
        $pagesLeft = true;

        while ($pagesLeft == true) {
          $posts = json_decode(WpJSONClient::listPosts([
            'categories' => join(",", $categoryIds),
            'per_page' => $perPage, 
            'page' => $pageNumber
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
        foreach ($posts as $post) {
          // If post is downloaded from this wordpress installation we don't want to duplicate it
          if ($post['metadata']['sourceWordpressName'] == Settings::getValue('wordpressName')) {
            continue;
          }

          $postId = $post['id'];
          $postObject['post_date'] = $post['date'];
          $postObject['post_title'] = $post['title']['rendered'];
          $postObject['post_content'] = $post['content']['rendered'];
          $postObject['post_excerpt'] = $post['excerpt']['rendered'];
          $postObject['post_type'] = 'post';
          $postObject['post_status'] = 'draft';
          $postObject['post_category'] = $this->getCategoryIdsByPost($post);
          
          $wpPostId = get_post_meta($this->getPluginCustomPostId(), sprintf("integrated_post_id_%d", $postId), true);

          if (empty($wpPostId)) {
            $newPostId = wp_insert_post($postObject);
            $this->generateFeaturedImage($post['featured_image_url'], $wpPonewPostIdstId);
            update_post_meta($this->getPluginCustomPostId(), sprintf("integrated_post_id_%s", $postId), $newPostId);
            update_post_meta($newPostId, 'sourceWordpressName', Settings::getValue('sourceWordpressName'));
          } else {
            $postObject['ID'] = $wpPostId;
            wp_update_post($postObject, true);
            $this->generateFeaturedImage($post['featured_image_url'], $wpPostId);
            update_post_meta($wpPostId, 'sourceWordpressName', Settings::getValue('sourceWordpressName'));
          }
        }
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
          'post_status' => 'inherit'
        );
        $attachId = wp_insert_attachment($attachment, $file, $postId);
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attachData = wp_generate_attachment_metadata($attachId, $file);
        wp_update_attachment_metadata($attachId, $attachData);
        set_post_thumbnail($postId, $attachId);
      }

      /**
       * Get category ids from the another wp installation
       */
      private function getOtherWpCategoryIds() {
        $perPage = 100;
        $page = 1;
        $categoriesLeft = true;

        while ($categoriesLeft == true) {
          $allCategories = json_decode(WpJSONClient::listCategories([
            'per_page' => $perPage, 
            'page' => $pageNumber
          ]), true);

          if (count($allCategories) < 100) {
            $categoriesLeft = false;
          }
          
          $wantedCategoryNames = array_map('strtolower', explode(",", Settings::getValue('categories')));
          $categoryIds = [];

          foreach ($allCategories as $category) {
            if (in_array(strtolower($category->name), $wantedCategoryNames)) {
              array_push($categoryIds, $category->id);
            }
          }
        }

        return $categoryIds;
      }

      /**
       * Get category ids by post from this wp installation
       * If there is no category we create one
       * 
       * @param $post post
       */
      private function getCategoryIdsByPost($post) {
        $postCategoryIds = $post['categories'];
        $allCategories = json_decode(WpJSONClient::listCategories());
        $categoryIds = [];

        foreach ($allCategories as $category) {
          if (in_array($category->id, $postCategoryIds)) {
            $categoryId = get_cat_ID($category->name);

            if ($categoryId == 0) {
              $categoryId = wp_create_category($category->name);
            }

            array_push($categoryIds, $categoryId);
          }
        }
        return $categoryIds;
      }

      /**
       * Get plugins custom post id
       */
      private function getPluginCustomPostId() {
        global $wpdb;

        $postIds = $wpdb->get_results("SELECT ID FROM wp_posts WHERE post_type='integration_tool'");

        if (count($postIds) == 0) {
          $id = $this->createCustomPost();
        } else {
          $id = $postIds[0]->ID;
        }

        return $id;
      }

      /**
       * Create custom post to store plugin meta data
       */
      private function createCustomPost() {
        global $wpdb;

        $postData = [
          'post_content' => 'Integration tool',
          'post_type' => 'integration_tool'
        ];

        return wp_insert_post($postData, true);
      }

    }
  }
  
  new WpPostsIntegrationTool();
?>