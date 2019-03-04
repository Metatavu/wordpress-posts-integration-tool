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

        while ($pagesLeft) {
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
        $sourceName = Settings::getValue('sourceWordpressName');

        foreach ($posts as $post) {
          $postId = $post['id'];
          $postObject['post_date'] = $post['date'];
          $postObject['post_title'] = $post['title']['rendered'];
          $postObject['post_content'] = $post['content']['rendered'];
          $postObject['post_excerpt'] = $post['excerpt']['rendered'];
          $postObject['post_type'] = 'post';
          $postObject['post_status'] = 'draft';
          $postObject['post_category'] = $this->getCategoryIdsByPost($post);
  
          $importedPostMeta = $this->getImportedPostMeta($sourceName, $postId);

          if (count($importedPostMeta) > 0) {
            $postObject['ID'] = $importedPostMeta[0]->post_id;
            wp_update_post($postObject, true);
            $this->generateFeaturedImage($post['featured_image_url'], $importedPostMeta[0]->post_id);
          } else {
            $newPostId = wp_insert_post($postObject);
            $this->generateFeaturedImage($post['featured_image_url'], $newPostId);
            update_post_meta($newPostId, 'postsIntegrationToolSource', "$sourceName:$postId");
          }
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
        $pageNumber = 1;
        $categoriesLeft = true;
        $categoryIds = [];

        while ($categoriesLeft == true) {
          $allCategories = json_decode(WpJSONClient::listCategories([
            'per_page' => $perPage, 
            'page' => $pageNumber
          ]), true);
          
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

      /**
       * Get category ids by post from this wp installation
       * If there is no category we create one
       * 
       * @param $post post
       */
      private function getCategoryIdsByPost($post) {
        $perPage = 100;
        $pageNumber = 1;
        $categoriesLeft = true;
        $categoryIds = [];

        $postCategoryIds = $post['categories'];
        $categoryIds = [];

        while ($categoriesLeft == true) {
          $allCategories = json_decode(WpJSONClient::listCategories([
            'per_page' => $perPage, 
            'page' => $pageNumber
          ]));

          if (count($allCategories) < $perPage) {
            $categoriesLeft = false;
          }
          
          foreach ($allCategories as $category) {
            if (in_array($category->id, $postCategoryIds)) {
              $categoryId = get_cat_ID($category->name);
  
              if ($categoryId == 0) {
                $categoryId = wp_create_category($category->name);
              }
  
              array_push($categoryIds, $categoryId);
            }
          }

          $pageNumber++;
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