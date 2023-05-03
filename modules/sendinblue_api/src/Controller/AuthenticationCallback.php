<?php

namespace Drupal\sendinblue_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\sendinblue_api\Service\SendinblueApi;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Sendinblue API Callback Controller.
 *
 * @package Drupal\sendinblue_api\Controller
 */
class AuthenticationCallback extends ControllerBase
{
  /**
   * Config factory service.
   *
   * @var Drupal\Core\Config\ConfigFactory
   */
  protected $config;

  /**
   * Symfony\Component\HttpFoundation\RequestStack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Drupal\Core\Messenger\MessengerInterface.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   *   Messenger Interface.
   */
  protected $messenger;

  /**
   * GuzzleHttp\Client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $client;

  /**
   * Constructor function.
   *
   * @param \Drupal\Core\Config\ConfigFactory $config
   *   Config factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Messenger interface.
   * @param Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   Request Stack.
   * @param GuzzleHttp\Client $client
   *   Guzzle HTTP client.
   * @param \Drupal\sendinblue_api\Service\SendinblueApi $sendinblueApi
   *   Sendinblue api service.
   */
  public function __construct(ConfigFactory $config, MessengerInterface $messenger, RequestStack $request_stack, Client $client, SendinblueApi $sendinblueApi)
  {
    $this->config = $config;
    $this->messenger = $messenger;
    $this->client = $client;
    $this->requestStack = $request_stack;
    $this->sendinblueApi = $sendinblueApi;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('config.factory'),
      $container->get('messenger'),
      $container->get('request_stack'),
      $container->get('http_client'),
      $container->get('sendinblue_api')
    );
  }

  /**
   * Callback URL handling for Sendinblue API API.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request.
   *
   * @return array
   *   Return markup for the page.
   */
  public function callbackUrl(Request $request)
  {
    $code = $request->get('code');
    $error = $request->get('error');
    $errorDescription = $request->get('error_description');

    $settings = $this->sendinblueApi->getConfig();
    $api_key = isset($settings['api_key']) ? $settings['api_key'] : NULL;
    $tokenUrl = isset($settings['token_url']) ? $settings['token_url'] : NULL;
    $client_secret = isset($settings['client_secret']) ? $settings['client_secret'] : NULL;
    $codeVerifier = isset($settings['code_verifier']) ? $settings['code_verifier'] : NULL;

    $hasRequiredFields = false;

    $hasRequiredFields = $api_key && $client_secret && $code;

    if ($hasRequiredFields === true) {
      try {
        $client = $this->client;
        $formParams = [
          'code' => $code,
          'redirect_uri' => $this->requestStack->getCurrentRequest()->getSchemeAndHttpHost() . '/admin/config/services/sendinblue-api/callback',
          'grant_type' => 'authorization_code',
          'scope' => 'contact_data+campaign_data+offline_access'
        ];
        $headers = [];

        $headers = [
          'Authorization' => 'Basic ' . base64_encode($api_key . ':' . $client_secret),
        ];

        $response = $client->request('POST', $tokenUrl, [
          'headers' => $headers,
          'form_params' => $formParams,
        ]);

        $json = json_decode($response->getBody()->getContents());

        if ($json && property_exists($json, 'access_token')) {
          $tokens = $this->config->getEditable('sendinblue_api.tokens');
          $tokens->clear('sendinblue_api.tokens');
          $tokens->set('access_token', $json->access_token);
          $tokens->set('timestamp', strtotime('now'));
          $tokens->save();

          $this->messenger->addMessage($this->t('Tokens were successfully saved.'));
        } else {

          $this->messenger->addMessage($this->t('There was a problem authorizing your account.'), $this->messenger::TYPE_ERROR);
        }
      } catch (RequestException $e) {
        watchdog_exception('sendinblue_api', $e);
        $this->messenger->addMessage($this->t('There was a problem authorizing your account.'), $this->messenger::TYPE_ERROR);
      } catch (\Exception $e) {
        watchdog_exception('sendinblue_api', $e);
        $this->messenger->addMessage($this->t('There was a problem authorizing your account.'), $this->messenger::TYPE_ERROR);
      }
    } else {
      $message = 'There was a problem authorizing your account. <br/>';

      if ($error) {
        $message .= 'Error: ' . $error . '<br/>';
      }

      if ($errorDescription) {
        $message .= '  Description: ' . $errorDescription;
      }

      $this->messenger->addMessage($this->t($message), $this->messenger::TYPE_ERROR);
    }

    return new RedirectResponse(Url::fromRoute('sendinblue_api.config')->toString());
  }

}