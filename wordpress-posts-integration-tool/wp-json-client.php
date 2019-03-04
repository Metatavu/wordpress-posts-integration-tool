<?php
  namespace Metatavu\PostIntegrationTool;
  
  if (!defined('ABSPATH')) { 
    exit;
  }

  require_once( __DIR__ . '/../vendor/autoload.php');
  require_once( __DIR__ . '/../settings/settings.php');

  use GuzzleHttp\Client;
  use GuzzleHttp\Psr7\Request;

  if (!class_exists( '\Metatavu\PostIntegrationTool\WpJSONClient' ) ) {
    
    /**
     * Class to make requests to wp rest api
     */
    class WpJSONClient {

      /**
       * Returns base url
       * 
       * @return String base url
       */
      public static function getBaseUrl() {
        return Settings::getValue('url');
      }
      
      /**
       * Returns client
       * 
       * @return GuzzleHttp\Client
       */
      public static function getClient() {
        return new Client();
      }

      /**
       * Lists posts 
       * 
       * @param $query Query string to url
       */
      public static function listPosts($query) {
        $baseUrl = self::getBaseUrl();

        if (empty($baseUrl)) {
          return;
        }

        $url = sprintf("%s/wp/v2/posts?%s", $baseUrl, http_build_query($query));
        return self::doGetRequest($url);
      }

      /**
       * Lists categories 
       */
      public static function listCategories($query) {
        $baseUrl = self::getBaseUrl();

        if (empty($baseUrl)) {
          return;
        }

        $url = sprintf("%s/wp/v2/categories?%s", $baseUrl, http_build_query($query));
        return self::doGetRequest($url);
      }

      /**
       * Do get request
       * 
       * @param $url url
       */
      private static function doGetRequest($url) {
        $client = self::getClient();

        try {
          $response = $client->get($url, [
            'http_errors' => false,
            'headers' => [
              'Accept' => 'application/json',
              'Content-type' => 'application/json'
            ], 
            'allow_redirects' => [
              'max' => 50,
            ]
          ]);
        } catch (ClientErrorResponseException $exception) {
          error_log(print_r($exception,true));
        }

        return $response->getBody()->getContents();
      }

    }
  }
  
?>