<?php

namespace Drupal\sendinblue_api\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\sendinblue_api\Service\SendinblueApi;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class SendinblueApiConfig.
 *
 * Configuration form for adjusting content for the social feeds block.
 */
class SendinblueApiConfig extends ConfigFormBase
{

  /**
   * Drupal\Core\Messenger\MessengerInterface.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   *   Messenger Interface.
   */
  protected $messenger;

  /**
   * Symfony\Component\HttpFoundation\RequestStack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Drupal\sendinblue_api\Service\SendinblueApi.
   *
   * @var \Drupal\sendinblue_api\Service\SendinblueApi
   *   Sendinblue api service.
   */
  protected $sendinblueApi;

  /**
   * SendinblueApiConfig constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Drupal\Core\Config\ConfigFactoryInterface.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Drupal\Core\Messenger\MessengerInterface.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   Symfony\Component\HttpFoundation\RequestStack.
   * @param \Drupal\sendinblue_api\Service\SendinblueApi $sendinblueApi
   *   Sendinblue api service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, MessengerInterface $messenger, RequestStack $request_stack, SendinblueApi $sendinblueApi)
  {
    parent::__construct($config_factory);
    $this->messenger = $messenger;
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
      $container->get('sendinblue_api')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'sendinblue_api_configure';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames()
  {
    return [
      'sendinblue_api.config'
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $settings = $this->sendinblueApi->getConfig();
    $clientId = isset($settings['api_key']) ? $settings['api_key'] : NULL;
    $configType = isset($settings['config_type']) ? $settings['config_type'] : 'config';
    $codeChallenge = null;

    if (version_compare(PHP_VERSION, '8.0', '<')) {
      $this->messenger->addMessage($this->t('The Sendinblue module is not compatible with your current version of PHP and should be upgraded to latest version or greater than 8.0.'), 'error');
    }

    $form['auth'] = [
      '#type' => 'details',
      '#title' => $this->t('Authorization Settings'),
      '#collapsible' => TRUE,
      '#open' => (!$clientId),
    ];

    $form['auth']['message'] = [
      '#markup' => $configType === 'settings.php' ? '<p>' . $this->t('<strong>NOTE:</strong> Application settings were found in your <strong>settings.php</strong> file. Please update information there or remove to use this form.') . '</p>' : '<p>' . $this->t('<strong>NOTE:</strong> Application settings are more secure when saved in your <strong>settings.php</strong> file. Please consider moving this information there. Example:') . '</p><pre>  $settings[\'sendinblue_api\'] = [
      \'api_key\' => \'your_api_key\',
    ];</pre>',
    ];

    $form['auth']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#default_value' => $clientId ? $clientId : NULL,
      '#required' => TRUE,
      '#disabled' => $configType === 'settings.php' ? TRUE : FALSE
    ];

    $buttonLabel = $this->t('Refresh Tokens Manually');
    $additionalMarkup = '';

    $form = parent::buildForm($form, $form_state);

    if ($configType === 'settings.php') {
      unset($form['actions']['submit']);
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $config = $this->config('sendinblue_api.config');
    $clientId = $form_state->getValue('api_key');
    $config->clear('sendinblue_api.config');
    $config->set('api_key', $clientId);
    $config->save();

    $this->messenger->addMessage($this->t('Your configuration has been saved'));
  }

}