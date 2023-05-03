<?php

namespace Drupal\sendinblue_api\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxy;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ClientException;
use SendinBlue\Client\Configuration;
use SendinBlue\Client\Api\ContactsApi;
use SendinBlue\Client\Model\CreateContact;
use SendinBlue\Client\Model\AddContactToList;
use SendinBlue\Client\Model\RemoveContactFromList;
use SendinBlue\Client\Model\UpdateContact;

/**
 * Class SendinblueApi.
 *
 * Class to handle API calls to Sendinblue API.
 */
class SendinblueApi {

  use StringTranslationTrait;

  /**
   * Drupal\Core\Cache\CacheBackendInterface.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   *   Drupal cache.
   */
  protected $cache;

  /**
   * Drupal\Core\Config\ConfigFactory.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   *   Drupal config.
   */
  protected $config;

  /**
   * Drupal\Core\Session\AccountProxy.
   *
   * @var \Drupal\Core\Session\AccountProxy
   *   Drupal current user.
   */
  protected $currentUser;

  /**
   * Drupal\Core\Logger\LoggerChannelFactoryInterface.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   *   Drupal logging.
   */
  protected $loggerFactory;

  /**
   * Drupal\Core\Messenger\MessengerInterface.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   *   Messenger Interface.
   */
  protected $messenger;

  /**
   * Drupal\Core\Site\Settings.
   *
   * @var \Drupal\Core\Site\Settings
   *   Drupal site settings.
   */
  protected $settings;

  /**
   * GuzzleHttp\Client.
   *
   * @var \GuzzleHttp\Client
   *   Guzzle HTTP Client.
   */
  protected $httpClient;

  /**
   * \Drupal\Core\Extension\ModuleHandlerInterface
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   *   Module handler interface
   */
  protected $moduleHandler;

  /**
   * Constructs the class.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The interface for cache implementations.
   * @param \Drupal\Core\Config\ConfigFactory $config
   *   The configuration object factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The factory for logging channels.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The runtime messages sent out to individual users on the page.
   * @param \Drupal\Core\Site\Settings $settings
   *   The read settings that are initialized with the class.
   * @param \GuzzleHttp\Client $httpClient
   *   The client for sending HTTP requests.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   */
  public function __construct(CacheBackendInterface $cache, ConfigFactory $config, AccountProxy $currentUser, LoggerChannelFactoryInterface $loggerFactory, MessengerInterface $messenger, Settings $settings, Client $httpClient, ModuleHandlerInterface $moduleHandler) {
    $this->cache = $cache;
    $this->config = $config;
    $this->currentUser = $currentUser;
    $this->loggerFactory = $loggerFactory->get('sendinblue_api');
    $this->messenger = $messenger;
    $this->settings = $settings;
    $this->httpClient = $httpClient;
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * Returns the configurations for the class.
   *
   * @return array
   *   Returns an array with all configuration settings.
   */
  public function getConfig() {
    // Get our settings from settings.php.
    $settings = $this->settings::get('sendinblue_api');
    $api_key = isset($settings['api_key']) ? $settings['api_key'] : NULL;
    $configType = 'settings.php';

    // If nothing is in settings.php, let's check our config files.
    if (!$settings) {
      $api_key = $this->config->get('sendinblue_api.config')->get('api_key');
      $configType = 'config';
    }

    if (!$this->moduleHandler->moduleExists('automated_cron') && !$this->moduleHandler->moduleExists('ultimate_cron') && (int)$this->currentUser->id() !== 0 && $this->currentUser->hasPermission('administer sendinblue api configuration')) {
      $this->messenger->addMessage($this->t('It is recommended to install automated_cron or make sure that cron is run regularly to refresh access tokens from Sendinblue API API.'), 'warning');
    }

    return [
      'api_key' => $api_key,
      'config_type' => $configType, // Application api_key and other info found in settings.php or via config

      // Add fields to configuration for signup form block configuration
      // @see https://developers.sendinblue.com
      'fields' => [
        'name' => 'Name',
      ],
    ];
  }

  public function base64UrlEncode($string) {
    $base64 = base64_encode($string);
    $base64 = trim($base64, '=');
    $base64url = strtr($base64, '+/', '-_');

    return $base64url;
  }

  /**
   * Generates a random PKCE Code Verifier.
   *
   * https://datatracker.ietf.org/doc/html/rfc7636#section-4.1
   * https://developers.sendinblue.com
   *
   * @return string
   */
  public function generateCodeVerifier() {
    $random = bin2hex(openssl_random_pseudo_bytes(32));
    $verifier = $this->base64UrlEncode(pack('H*', $random));

    return $verifier;
  }

  /**
   * Return the encoded code challenge for the PKCE Code Verifier to send to API Authorization Endpoint.
   *
   * https://developers.sendinblue.com
   *
   * @param string $codeVerifier
   * @return string
   */
  public function getCodeChallenge($codeVerifier) {
    return $this->base64UrlEncode(pack('H*', hash('sha256', $codeVerifier)));
  }

  /**
   * Shared method to generate the rest of the request body.
   * 
   * @NOTE that email_address, permission_to_send are not added hear since the fields are
   * different per api call type. For example, the list_memberships, the email_address field. 
   * 
   * @see https://developers.sendinblue.com
   *
   * @param array $data - posted data from our form
   * @param object $body - An object already generated.
   * @return object $body
   */
  protected function buildResponseBody(array $data, object $body) {

    $fields = $this->getConfig()['fields'];

    foreach ($fields as $field => $name) {
      if (isset($data[$field]) && $data[$field]) {
        $body->{$field} = $data[$field];
      }
    }


    if (isset($data['custom_fields']) && count($data['custom_fields']) > 0) {
      foreach ($data['custom_fields'] as $id => $value) {
        $body->custom_fields[] = ['custom_field_id' => $id, 'value' => $value];
      }
    }

    return $body;
  }

  /**
   * Creates a new contact by posting to Sendinblue API API.
   *
   * @param array $data
   *   Array of data to send to Sendinblue API.
   *    Requires 'email_address' key.
   *    Can also accept 'name'.
   * @param array $listIDs
   *   An array of list UUIDs where we want to add this contact.
   *
   * @see https://developers.sendinblue.com#!/Contacts/createContact
   */
  private function createContact(array $data, $listIDs) {
    $config = $this->getConfig();

    $body = (object) [
      'email_address' => (object) [
        'address' => NULL,
        'permission_to_send' => NULL,
      ],
      'name' => NULL,
      'create_source' => NULL,
      'list_memberships' => NULL,
    ];

    $body = $this->buildResponseBody($data, $body);

    // Add our required fields.
    $body->email_address->address = $data['email_address'];
    $body->email_address->permission_to_send = 'implicit';
    $body->list_memberships = $listIDs;
    $body->create_source = 'Account';

    $this->moduleHandler->invokeAll('sendinblue_api_contact_data_alter', [$data, &$body]);
    $this->moduleHandler->invokeAll('sendinblue_api_contact_create_data_alter', [$data, &$body]);

    try {
      $response = $this->httpClient->request('POST', $config['contact_url'], [
        'headers' => [
          'Authorization' => 'Bearer ' . $config['api_key'],
          'cache-control' => 'no-cache',
          'content-type' => 'application/json',
          'accept' => 'application/json',
        ],
        'body' => json_encode($body),
      ]);

      $this->handleResponse($response, 'createContact');
    }
    catch (RequestException $e) {
      // Return the error to show an error on form submission
      return $this->handleRequestException($e);
    }
    catch (ClientException $e) {
      $this->handleRequestException($e);
    }
    catch (\Exception $e) {
      $this->loggerFactory->error($e);

      // Return the error to show an error on form submission
      return ['error' => $e];
    }
  }


  /**
   * Fetch the details of a single campaign.
   *
   * @param string $id
   *   The id of the campaign.
   *
   * @return mixed
   *   An stdClass of the campaign.
   * @throws \GuzzleHttp\Exception\GuzzleException
   * 
   * @see https://developers.sendinblue.com
   */
  public function getCampaign(string $id) {
    $config = $this->getConfig();
    try {
      $response = $this->httpClient->request('GET', $config['campaigns_url'] . '/' . $id, [
        'headers' => [
          'Authorization' => 'Bearer ' . $config['api_key'],
          'cache-control' => 'no-cache',
          'content-type' => 'application/json',
          'accept' => 'application/json',
        ],
      ]);

      $this->updateTokenExpiration();
      $json = json_decode($response->getBody()->getContents());
      return $json;
    }
    catch (RequestException $e) {
      $this->handleRequestException($e);
    }
    catch (ClientException $e) {
      $this->handleRequestException($e);
    }
    catch (\Exception $e) {
      $this->loggerFactory->error($e);
    }
  }

  /**
   * Get the campaign activity details by id.
   *
   * @param string $id
   *   The id of the activity.
   *
   * @return mixed
   *   A stdClass of the activity.
   * @throws \GuzzleHttp\Exception\GuzzleException
   * 
   * @see https://developers.sendinblue.com
   */
  public function getCampaignActivity(string $id) {
    $config = $this->getConfig();
    $url = $config['campaign_activity_url'] . '/' . $id;
    $url .= '?include=permalink_url';
    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $config['api_key'],
          'cache-control' => 'no-cache',
          'content-type' => 'application/json',
          'accept' => 'application/json',
        ],
      ]);

      $this->updateTokenExpiration();
      $json = json_decode($response->getBody()->getContents());
      return $json;
    }
    catch (RequestException $e) {
      $this->handleRequestException($e);
    }
    catch (ClientException $e) {
      $this->handleRequestException($e);
    }
    catch (\Exception $e) {
      $this->loggerFactory->error($e);
    }
  }

  /**
   * Returns a list of the campaigns.
   *
   * @param array $status
   *   An option to filter campaigns by status.
   *
   * @return array
   *   An array of campaigns.
   * @throws \GuzzleHttp\Exception\GuzzleException
   * 
   * @see https://developers.sendinblue.com
   */
  public function getCampaigns($status = []) {
    $config = $this->getConfig();
    try {
      $response = $this->httpClient->request('GET', $config['campaigns_url'], [
        'headers' => [
          'Authorization' => 'Bearer ' . $config['api_key'],
          'cache-control' => 'no-cache',
          'content-type' => 'application/json',
          'accept' => 'application/json',
        ],
      ]);

      $this->updateTokenExpiration();
      $json = json_decode($response->getBody()->getContents());
      $list = [];

      foreach ($json->campaigns as $campaign) {
        if (!empty($status) && in_array($campaign->current_status, $status)) {
          $list[] = $campaign;
        }
        else if (empty($status)) {
          $list[] = $campaign;
        }

      }
      return $list;
    }
    catch (RequestException $e) {
      $this->handleRequestException($e);
    }
    catch (ClientException $e) {
      $this->handleRequestException($e);
    }
    catch (\Exception $e) {
      $this->loggerFactory->error($e);
    }
  }

  /**
   * Checks if a contact exists already.
   *
   * @param array $data
   *   Array of data to send. 'email_address' key is required.
   *
   * @return array
   *   Returns a json response that determines if a contact
   *   already exists or was deleted from the list.
   *
   * @see https://developers.sendinblue.com
   */
  private function getContact(array $data) {
    $config = $this->getConfig();
    $apikey = $config['api_key'];

    try {

       // Configure API key authorization: api-key
      $sendinblue_config = Configuration::getDefaultConfiguration()->setApiKey('api-key', $apikey);
      $apiInstance = new ContactsApi(
        new Client(),
        $sendinblue_config
      );

      $email_address = $data['email_address'];
      $startDate = '';
      $endDate = '';

      // get subscriber by email
      $result = $apiInstance->getContactInfo($email_address, $startDate, $endDate);

      $return_data = array();

      if($result) {
        $return_data['uid'] = $result->getId();
      }

      return $return_data;
    }
    catch (RequestException $e) {
      // Return the error to show an error on form submission
      return $this->handleRequestException($e);
    }
    catch (ClientException $e) {
      $this->handleRequestException($e);
    }
    catch (\Exception $e) {
      $this->loggerFactory->error($e);

      // Return the error to show an error on form submission
      return ['error' => $e];
    }
  }

  /**
   * Gets contact lists from Sendinblue API API.
   * 
   * @param $cached 
   *  Whether to return a cached response or not. 
   *  @see https://www.drupal.org/project/sendinblue_api/issues/3282088 and https://developers.sendinblue.com
   *  Cron run perhaps was calling Drupal cached version which may have allowed refresh tokens to expire.
   *
   * @return array
   *   Returns an array of lists that the account has access to.
   *
   * @see https://developers.sendinblue.com
   * 
   */
  public function getMailingLists($cached = true) {
    $config = $this->getConfig();

    $cid = 'sendinblue_api.lists';
    $cache = ($cached === true ? $this->cache->get($cid) : null);

    if ($cache && $cache->data && count($cache->data) > 0) {
      return $cache->data;
    }
    else {

      if (isset($config['api_key'])) {

        $api_key = $config['api_key'];

          // Configure API key authorization: api-key
        $sendinblue_config = Configuration::getDefaultConfiguration()->setApiKey('api-key', $api_key);

        $apiInstance = new ContactsApi(
          // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
          // This is optional, `GuzzleHttp\Client` will be used as default.
          new Client(),
          $sendinblue_config
        );

        $sendinblue_lists = array();

        try {
          for ($i=0; $i < 10 ; $i++) {
           $limit = 50;
            // int | Number of documents per page
           $offset = 0;
            // int | Index of the first document of the page
           $sort = "desc";
            // string | Sort the results in the ascending/descending order of record creation. Default order is **descending** if `sort` is not passed

           $result = $apiInstance->getLists($limit, $offset, $sort);
           if($result && isset($result['lists'])) {
            $sendinblue_lists = $sendinblue_lists + $result['lists'];
          } else {
            break;
          }
        }

        if (!empty($sendinblue_lists)) {
          $lists = array();


          foreach ($sendinblue_lists as $list) {

            $list_id = $list['id'];
            $list_name = $list['name'];
            $folderId = $list['folderId'];
            $uniqueSubscribers = $list['uniqueSubscribers'];
            $totalBlacklisted = $list['totalBlacklisted'];
            $totalSubscribers = $list['totalSubscribers'];

            $list_data = array(
              'list_id' => $list_id,
              'name' => $list_name,
              'folderId' => $folderId,
              'uniqueSubscribers' => $uniqueSubscribers,
              'totalBlacklisted' => $totalBlacklisted,
              'totalSubscribers' => $totalSubscribers,
            );
            $lists[$list_id] = (object)$list_data;

          }

          $this->saveContactLists($lists);
          return $lists;
        } else {
          $this->messenger->addMessage($this->t('There was a problem getting your available contact lists.'), 'error');
        }

      }
      catch (RequestException $e) {
        $this->handleRequestException($e);
        $this->messenger->addMessage($this->t('There was a problem getting your available contact lists.'), 'error');
      }
      catch (ClientException $e) {
        $this->handleRequestException($e);
        $this->messenger->addMessage($this->t('There was a problem getting your available contact lists.'), 'error');
      }
      catch (\Exception $e) {
        $this->loggerFactory->error($e);
        $this->messenger->addMessage($this->t('There was a problem getting your available contact lists.'), 'error');
      }
    }
    else {
      return [];
    }
  }
}

  /**
   * Returns custom fields available
   * 
   * @param $cached 
   *  Whether to return a cached response or not. 
   * 
   * @return mixed
   *   A stdClass of custom fields.
   * 
   * @see https://developers.sendinblue.comapi_guide/get_custom_fields.html
   */
  public function getCustomFields($cached = true) {
    $config = $this->getConfig();
    $cid = 'sendinblue_api.custom_fields';
    $cache = ($cached === true ? $this->cache->get($cid) : null);

    if ($cache && !is_null($cache) && $cache->data && property_exists($cache->data, 'custom_fields')) {
      return $cache->data;
    }
    else {
      if (isset($config['api_key'])) {

       $api_key = $config['api_key'];
       $custom_fields_data = new \stdClass();

       try {
          // Configure API key authorization: api-key
         $sendinblue_config = Configuration::getDefaultConfiguration()->setApiKey('api-key', $api_key);

         $apiInstance = new ContactsApi(
          // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
          // This is optional, `GuzzleHttp\Client` will be used as default.
          new Client(),
          $sendinblue_config
        );

         $custom_fields = array();
         $custom_fields_container = $apiInstance->getAttributes();
         if($custom_fields_container && isset($custom_fields_container['attributes'])) {
           $custom_field_attributes = $custom_fields_container['attributes'];
           $ignored_fields = array('BLACKLIST', 'READERS', 'CLICKERS', 'DOUBLE_OPT-IN', 'OPT_IN');

           foreach ($custom_field_attributes as $key => $custom_field_container) {
            $field_name = $custom_field_container['name'];
            if(!in_array($field_name, $ignored_fields)) {
              $category = $custom_field_container['category'];
              $type = $custom_field_container['type'];

              $data_value = new \stdClass();
              $data_value->name = $field_name;
              $data_value->label = $field_name;
              $data_value->type = $type;
              $data_value->custom_field_id = $field_name;
              $data_value->is_required = null;

              $custom_fields[] = $data_value;
            }
          }
        }

        $custom_fields_data->custom_fields = $custom_fields;

        $this->cache->set($cid, $custom_fields_data);

        return $custom_fields_data;
      }
      catch (RequestException $e) {
        $this->handleRequestException($e);
      }
      catch (ClientException $e) {
        $this->handleRequestException($e);
      }
      catch (\Exception $e) {
        $this->loggerFactory->error($e);
      }
    }
  }
}

  /**
   * Checks if a contact is deleted from a list.
   *
   * This loops through all the deleted contacts of a
   * list and returns if there is a match to the email address.
   *
   * @param string $endpoint
   *   The endpoint to check. @see $this->getContact()
   * @param string $email
   *   The email address we're looking for.
   *
   * @return array
   *   Returns an array of a matched deleted contact.
   *
   * @see https://developers.sendinblue.com
   */
  private function getDeleted($endpoint, $email) {
    $config = $this->getConfig();

    $deleted = $this->httpClient->request('GET', $endpoint, [
      'headers' => [
        'Authorization' => 'Bearer ' . $config['api_key'],
        'cache-control' => 'no-cache',
        'content-type' => 'application/json',
        'accept' => 'application/json',
      ],
    ]);

    $deleted = json_decode($deleted->getBody()->getContents());
    $match = NULL;

    if (count($deleted->contacts)) {
      foreach ($deleted->contacts as $value) {
        if ($value->email_address->address === $email) {
          $match = $value;
        }
      }
    }

    if (!$match &&  property_exists($deleted, '_links') && property_exists($deleted->_links, 'next') && property_exists($deleted->_links->next, 'href')) {
      $match = $this->getDeleted('https://api.cc.email' . $deleted->_links->next->href, $email);
    }

    return $match;
  }

  /**
   * Get Enabled Contact Lists 
   *
   * @param boolean $cached
   * @return array $lists of enabled lists
   * 
   * @see /Drupal/Form/SendinblueApiLists.php
   */
  public function getEnabledMailingLists($cached = true) {
    $lists = $this->getMailingLists($cached);

    return $lists;
  }

  /**
   * Get the permanent link of a campaign.
   *
   * @param string $id
   *   The campaign id.
   *
   * @return null|string
   *   The URL of the campaign's permanent link.
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getPermaLinkFromCampaign(string $id) {
    $url = NULL;
    if (!$id) {
      return NULL;
    }
    $campaign = $this->getCampain($id);
    foreach ($campaign->campaign_activities as $activity) {
      if ($activity->role != 'permalink') {
        continue;
      }
      $act = $this->getCampaignActivity($activity->campaign_activity_id);
      if ($act) {
        return $act->permalink_url;
      }
    }
    return NULL;
  }

  /**
   * Handles an error
   *
   * @param [object] $error
   * @return [array] $return
   */
  protected function handleRequestException(object $e) {
    if(method_exists($e, 'getResponseBody')) {

      $response = $e->getResponseBody();

      $error = is_null($response) ? FALSE : json_decode($response);

      $message = 'RequestException: ';

      $errorInfo = [];

      if ($error && is_object($error)) {
        if (property_exists($error, 'error')) {
          $errorInfo[] = $error->error;
        }

        if (property_exists($error, 'message')) {
          $errorInfo[] = $error->message;
        }

        if (property_exists($error, 'error_description')) {
          $errorInfo[] = $error->error_description;
        }

        if (property_exists($error, 'errorCode')) {
          $errorInfo[] = $error->errorSummary;
        }

        if (property_exists($error, 'code')) {
          $errorInfo[] = $error->code;
        }
      }

      $message .= implode(', ', $errorInfo);

      $this->loggerFactory->error($message);

    // Return the error to show an error on form submission
      return ['error' => $message];
    }
  }

  /**
   * Handles API response for adding a contact.
   *
   * @param object $response
   *   The json_decoded json response.
   * @param string $method
   *   The name of the method that the response came from.
   *
   * @return array
   *   Returns an array that includes the method name and
   *   the statuscode except if it is coming from getContact method.
   *   Then it returns an array of the contact that matches.
   */
  private function handleResponse($response, $method) {
    if (isset($response['id']) || (isset($response['id']) && $method === 'createContact')) {
      $statuscode = '200';

      $this->loggerFactory->info('@method has been executed successfully.', ['@method' => $method]);

      $this->updateTokenExpiration();

      return [
        'method' => $method,
        'response' => $statuscode,
      ];
    } else {
      if(method_exists($response, 'getCode')) {
        $statuscode = $response->getCode();
        $responsecode = $response->getError();

        $this->loggerFactory->error('Call to @method resulted in @status response. @responsecode', [
          '@method' => $method,
          '@status' => $statuscode,
          '@responsecode' => $responsecode,
        ]);

        return [
          'error' => 'There was a problem signing up. Please try again later.',
        ];
      }
    }
  }

  /**
   * Submits a contact to the API. 
   * Used to be used on CostantContactBlockForm but using $this->submitContactForm instead.
   * Determine if contact needs to be updated or created.
   *
   * @param array $data
   *   Data to create/update a contact.
   *   Requires a 'email_address' key.
   *   But can also accept 'name' key.
   * @param array $listIDs
   *   An array of list UUIDs to post the contact to.
   *
   * @return array
   *   Returns an error if there is an error.
   *   Otherwise it sends the info to other methods.
   *
   * @see $this->updateContact
   * @see $this->createContact
   */
  public function postContact(array $data = [], $listIDs = []) {
    $config = $this->getConfig();
    $enabled = $this->config->get('sendinblue_api.enabled_lists')->getRawData();

    if (!$config['api_key'] || !$config['client_secret'] || !$config['api_key'] || !$data) {
      $msg = 'Missing credentials for postContact';

      $this->loggerFactory->error($msg);

      return [
        'error' => $msg,
      ];
    }

    if (!$listIDs || count($listIDs) === 0) {
      $msg = 'A listID is required.';

      $this->loggerFactory->error($msg);

      return [
        'error' => $msg,
      ];
    }

    foreach ($listIDs as $listID) {
      if (!isset($enabled[$listID]) || $enabled[$listID] !== 1) {
        $msg = 'The listID provided does not exist or is not enabled.';

        $this->loggerFactory->error($msg);

        return [
          'error' => $msg,
        ];
      }
    }

    if (!isset($data['email_address'])) {
      $msg = 'An email address is required';

      $this->loggerFactory->error($msg);

      return [
        'error' => $msg,
      ];
    }

    // Check if contact already exists.
    $exists = $this->getContact($data);

    // If yes, updateContact.
    // If no, createContact.
    if (isset($exists['uid'])) {
      $this->updateContact($data, $listIDs);
    }
    else {
      $this->submitContactForm($data, $listIDs);
    }
  }

  /**
   * Makes authenticated request to Sendinblue API to refresh tokens.
   *
   * @see https://developers.sendinblue.com
   */
  public function refreshToken($updateLists = true) {
    $config = $this->getConfig();

    if (!$config['api_key']) {
      return FALSE;
    }

    // @TODO - Fix for pkce flow. 
    // @see https://www.drupal.org/project/sendinblue_api/issues/3285446
    // @see https://developers.sendinblue.comapi_guide/pkce_flow.html
    try {

    }
    catch (RequestException $e) {
      return $this->handleRequestException($e);
    }
    catch (\Exception $e) {
      dpm($e);
      $this->loggerFactory->error($e);

      // Return the error to show an error on form submission
      return ['error' => $e];
    }
  }

  /**
   * Saves available contact lists to a cache.
   *
   * @param array $data
   *   An array of lists and list UUIDs from $this->getMailingLists.
   */
  public function saveContactLists(array $data) {
    $cid = 'sendinblue_api.lists';
    $enabled = $this->config->get('sendinblue_api.enabled_lists')->getRawData();
    
    // Add an enabled flag to the list data.
    foreach ($data as $key => $value) {
      $data[$key]->enabled = (isset($enabled[$key]) && $enabled[$key] === 1);
      $data[$key]->cached_on = strtotime('now'); 
    }

    $this->cache->set($cid, $data);
  }

  /**
   * Saves access and refresh tokens to our config database.
   *
   * @param object $data
   *   Data object of data to save the token.
   *
   * @see $this->refreshToken
   */
  private function saveTokens($data) {
    if ($data && property_exists($data, 'api_key')) {
      $tokens = $this->config->getEditable('sendinblue_api.tokens');
      $tokens->clear('sendinblue_api.tokens');
      $tokens->set('api_key', $data->api_key);
      $tokens->set('timestamp', strtotime('now'));

      if ($data->expires_in < $tokens->get('expires')) {
        $tokens->set('expires', $data->expires_in);
      }

      $tokens->save();

      $this->loggerFactory->info('New tokens saved at ' . date('Y-m-d h:ia', strtotime('now')));
    }
    else {
      $this->loggerFactory->error('There was an error saving tokens');
    }
  }

  /**
   * Submission of contact form.
   * Replaces $this->postContact as of v. 2.0.9.
   *
   * @param array $data
   *   Data to create/update a contact.
   *   Requires a 'email_address' key.
   *   But can also accept 'name' key.
   * @param array $listIDs
   *   An array of list UUIDs to post the contact to.
   *
   * @return array
   *   Returns an error if there is an error.
   *   Otherwise it sends the info to other methods.
   *
   * @see https://developers.sendinblue.com
   */
  public function submitContactForm(array $data = [], $listIDs = []) {
    $config = $this->getConfig();

    $enabled = $this->config->get('sendinblue_api.enabled_lists')->getRawData();

    if (!$config['api_key'] || !$data) {
      $msg = 'Missing credentials for postContact';

      $this->loggerFactory->error($msg);

      return [
        'error' => $msg,
      ];
    }

    if($listIDs && !is_array($listIDs)) {
      $listIDs = [$listIDs];
    }

    if (!$listIDs || count($listIDs) === 0) {

      $msg = 'A listID is required.';

      $this->loggerFactory->error($msg);

      return [
        'error' => $msg,
      ];
    }

    foreach ($listIDs as $listID) {
      if (!isset($enabled[$listID]) || $enabled[$listID] !== 1) {
        $msg = 'The listID provided does not exist or is not enabled.';

        $this->loggerFactory->error($msg);

        return [
          'error' => $msg,
        ];
      }
    }

    if (!isset($data['email_address'])) {
      $msg = 'An email address is required';

      $this->loggerFactory->error($msg);

      return [
        'error' => $msg,
      ];
    }

    $body = (object)[];

    $body = $this->buildResponseBody($data, $body);

    // Add required fields.
    $body->email_address = $data['email_address'];
    $body->list_memberships = $listIDs;

    $this->moduleHandler->invokeAll('sendinblue_api_contact_data_alter', [$data, &$body]);
    $this->moduleHandler->invokeAll('sendinblue_api_contact_form_submission_alter', [$data, &$body]);

    $email_address = $data['email_address'];
    $name = $data['name']??'';
    $custom_fields_data = $data['custom_fields']??[];
    $custom_fields = array();
    $format = "json";
    $apikey = $config['api_key'];
    $startDate = "";
    $endDate = "";

    if(!empty($custom_fields_data)) {
      foreach ($custom_fields_data as $field_key => $field_value) {
        $custom_fields[$field_key] = $field_value;
      }
    }

      // Configure API key authorization: api-key
    $sendinblue_config = Configuration::getDefaultConfiguration()->setApiKey('api-key', $apikey);
    $apiInstance = new ContactsApi(
      new Client(),
      $sendinblue_config
    );

    foreach ($listIDs as $key => $mailing_list_id) {
      try {
      // get subscriber by email
        $result = $apiInstance->getContactInfo($email_address, $startDate, $endDate);

      } catch (\Exception $e) {

      }

      if(isset($result)) {
        $user_id = $result['id'];
        $apiInstance = new ContactsApi(
          new Client(),
          $sendinblue_config
        );
        $contactEmails = new AddContactToList();

        $contactEmails['ids'] = [$user_id];

        try {
          $response = $apiInstance->addContactToList($mailing_list_id, $contactEmails);

        } catch (\Exception $e) {
         return $this->handleRequestException($e);

       }
     } else {

      $createContact = new CreateContact();
       // Values to create a contact

      $mailing_list_id = (int)$mailing_list_id;
      $createContact['email'] = $email_address;
      $createContact['listIds'] = [$mailing_list_id];

      if(!empty($custom_fields)) {
        $createContact['attributes'] = $custom_fields;
      }

      try {
        $response = $apiInstance->createContact($createContact);

        $this->handleResponse($response, 'submitContactForm');
      } catch (\Exception $e) {
        $responseBody = $e->getResponseBody();
        $json_decoded = json_decode($responseBody);
        $code = $json_decoded->code;

        $message = $json_decoded->message;
        if($message != '') {
          if(strtolower($message) == 'invalid phone number') {
            $message .= '. Mobile Number in SMS field should be passed with proper country code. For example: Accepted Number Formats are 91xxxxxxxxxx, +91xxxxxxxxxx, 0091xxxxxxxxxx';
          }
          $this->messenger->addMessage($this->t($message.'.'), 'error');
        }

        return $this->handleRequestException($e);
      }
    }
  }

}


  /**
   *  Unsubscribes a contact from all lists.
   *
   * @param array $contact
   *   The response from $this->getDeleted.
   * @param array $data
   *   The $data provided
   *
   * @see https://developers.sendinblue.com
   *
   */
  public function unsubscribeContact(array $data) {
    $config = $this->getConfig();
    // Check if contact already exists.
    $exists = (array) $this->getContact($data);
    $body = null;

    $email_address = $data['email_address'];
    $mailing_list_id = $data['mailing_list_id']??'';

    $this->moduleHandler->invokeAll('sendinblue_api_contact_data_alter', [$data, &$body]);
    $this->moduleHandler->invokeAll('sendinblue_api_contact_unsubscribe_data_alter', [$data, &$body]);

    try {

      $config = $this->getConfig();

      $api_key = $config['api_key'];

      // Configure API key authorization: api-key
      $sendinblue_config = Configuration::getDefaultConfiguration()->setApiKey('api-key', $api_key);

      if($mailing_list_id != '') {
        $apiInstance = new ContactsApi(
          new Client(),
          $sendinblue_config
        );

        $contactEmails = new RemoveContactFromList();
        $contactEmails['emails'] = [$email_address];

        $response = $apiInstance->removeContactFromList($mailing_list_id, $contactEmails);

        $this->handleResponse($response, 'unsubscribeContact');
      }
    }
    catch (RequestException $e) {
        // Return the error to show an error on form submission
      return $this->handleRequestException($e);
    }
    catch (ClientException $e) {
      return $this->handleRequestException($e);
    }
    catch (\Exception $e) {

      return $this->handleRequestException($e);
    }
  }

  /**
   * Updates a contact if it already exists on a list.
   *
   * @param array $data
   *   Provided data to update the contact with.
   * @param object $contact
   *   Contact match to provided data.
   * @param array $listIDs
   *   An array of list UUIDs that the contact is being updated on.
   *
   * @see https://developers.sendinblue.com
   */
  private function updateContact(array $data, $listIDs) {

    $config = $this->getConfig();

    $enabled = $this->config->get('sendinblue_api.enabled_lists')->getRawData();

    if (!$config['api_key'] || !$data) {
      $msg = 'Missing credentials for postContact';

      $this->loggerFactory->error($msg);

      return [
        'error' => $msg,
      ];
    }

    if($listIDs && !is_array($listIDs)) {
      $listIDs = [$listIDs];
    }

    if (!$listIDs || count($listIDs) === 0) {

      $msg = 'A listID is required.';

      $this->loggerFactory->error($msg);

      return [
        'error' => $msg,
      ];
    }

    foreach ($listIDs as $listID) {
      if (!isset($enabled[$listID]) || $enabled[$listID] !== 1) {
        $msg = 'The listID provided does not exist or is not enabled.';

        $this->loggerFactory->error($msg);

        return [
          'error' => $msg,
        ];
      }
    }

    if (!isset($data['email_address'])) {
      $msg = 'An email address is required';

      $this->loggerFactory->error($msg);

      return [
        'error' => $msg,
      ];
    }

    $body = (object)[];

    $body = $this->buildResponseBody($data, $body);

    // Add required fields.
    $body->email_address = $data['email_address'];
    $body->list_memberships = $listIDs;

    $this->moduleHandler->invokeAll('sendinblue_api_contact_data_alter', [$data, &$body]);
    $this->moduleHandler->invokeAll('sendinblue_api_contact_form_submission_alter', [$data, &$body]);

    $email_address = $data['email_address'];
    $name = $data['name']??'';
    $custom_fields_data = $data['custom_fields']??[];
    $custom_fields = array();
    $format = "json";
    $apikey = $config['api_key'];
    $startDate = "";
    $endDate = "";

    if(!empty($custom_fields_data)) {
      foreach ($custom_fields_data as $field_key => $field_value) {
        $custom_fields[$field_key] = $field_value;
      }
    }

      // Configure API key authorization: api-key
    $sendinblue_config = Configuration::getDefaultConfiguration()->setApiKey('api-key', $apikey);
    $apiInstance = new ContactsApi(
      new Client(),
      $sendinblue_config
    );

    foreach ($listIDs as $key => $mailing_list_id) {
      try {
      // get subscriber by email
        $result = $apiInstance->getContactInfo($email_address, $startDate, $endDate);

      } catch (\Exception $e) {

      }

      if(isset($result)) {
        $user_id = $result['id'];
        $apiInstance = new ContactsApi(
          new Client(),
          $sendinblue_config
        );

        try {

          if(!empty($custom_fields)) {

            $updateContact = new UpdateContact();
            $createContact['attributes'] = $custom_fields;

            $apiInstance->updateContact($email_address, $updateContact);

            $response = $apiInstance->addContactToList($mailing_list_id, $contactEmails);
          }
        } catch (\Exception $e) {
         return $this->handleRequestException($e);

       }
     } else {

      $createContact = new CreateContact();
       // Values to create a contact

      $mailing_list_id = (int)$mailing_list_id;
      $createContact['email'] = $email_address;
      $createContact['listIds'] = [$mailing_list_id];

      if(!empty($custom_fields)) {
        $createContact['attributes'] = $custom_fields;
      }

      try {
        $response = $apiInstance->createContact($createContact);

        $this->handleResponse($response, 'submitContactForm');
      } catch (\Exception $e) {
        $responseBody = $e->getResponseBody();
        $json_decoded = json_decode($responseBody);
        $code = $json_decoded->code;

        $message = $json_decoded->message;
        if($message != '') {
          if(strtolower($message) == 'invalid phone number') {
            $message .= '. Mobile Number in SMS field should be passed with proper country code. For example: Accepted Number Formats are 91xxxxxxxxxx, +91xxxxxxxxxx, 0091xxxxxxxxxx';
          }
          $this->messenger->addMessage($this->t($message.'.'), 'error');
        }

        return $this->handleRequestException($e);
      }
    }
  }


}

protected function updateTokenExpiration() {
  $tokens = $this->config->getEditable('sendinblue_api.tokens');
  $tokens->set('expires', strtotime('now +2 hours'));
  $tokens->save();
}

}
