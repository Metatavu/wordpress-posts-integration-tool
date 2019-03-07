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
        add_action('wpPostsIntegrationTask1', [$this, 'loopPosts']);

        if (!wp_next_scheduled('wpPostsIntegrationTask1')) {
          wp_schedule_event(time(), 'hourly', 'wpPostsIntegrationTask1');
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
        
        $categoryIds = $this->getOtherWpCategoryIds();
        $pageNumber = 1;
        $perPage = 30;
        $pagesLeft = true;

        while ($pagesLeft) {
          $posts = json_decode(WpJSONClient::listPosts([
            'categories' => join(",", $categoryIds),
            'per_page' => $perPage, 
            'page' => $pageNumber,
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
          $categories = $this->getCategoryIdsByPost($post);

          if (count($post['_embedded']['wp:featuredmedia']) > 0) {
            $imageUrl = $post['_embedded']['wp:featuredmedia'][0]['media_details']['sizes']['full']['source_url'];
          }
          
          $importedPostMeta = $this->getImportedPostMeta($sourceName, $postId);

          if (count($importedPostMeta) > 0) {
            wp_set_post_categories($importedPostMeta[0]->post_id, $categories);
            $postObject['ID'] = $importedPostMeta[0]->post_id;
            $existingPost = get_post($importedPostMeta[0]->post_id);
            $postObject['post_status'] = $existingPost['post_status'] == "publish" ? "publish" : "draft";
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

          if (count($allCategories) < $perPage || $allCategories['data']['status'] == 400) {
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

    }
  }

  new WpPostsIntegrationTool();
?>