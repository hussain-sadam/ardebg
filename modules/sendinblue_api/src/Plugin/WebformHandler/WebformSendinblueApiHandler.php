<?php

namespace Drupal\sendinblue_api\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Serialization\Yaml;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\WebformTokenManagerInterface;
use Drupal\sendinblue_api\Service\SendinblueApi;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Form submission to Sendinblue API handler.
 *
 * @WebformHandler(
 *   id = "sendinblue_api",
 *   label = @Translation("Sendinblue API"),
 *   category = @Translation("Sendinblue API"),
 *   description = @Translation("Sends a form submission to a Sendinblue API list."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class WebformSendinblueApiHandler extends WebformHandlerBase
{

  /**
   * Drupal\Core\Cache\CacheBackendInterface.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   *   Drupal cache.
   */
  protected $cache;

  /**
   * Drupal\sendinblue_api\Service\SendinblueApi.
   *
   * @var \Drupal\sendinblue_api\Service\SendinblueApi
   *   Sendinblue api service.
   */
  protected $sendinblueApi;

  /**
   * The token manager.
   *
   * @var \Drupal\webform\WebformTokenManagerInterface
   */
  protected $token_manager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
  {
    $instance = parent::create(
      $container,
      $configuration,
      $plugin_id,
      $plugin_definition
    );
    $instance->setCacheManager($container->get('cache.default'));
    $instance->setTokenManager($container->get('webform.token_manager'));
    $instance->setSendinblueApi($container->get('sendinblue_api'));
    return $instance;
  }

  /**
   * Set Cache dependency
   */
  protected function setCacheManager(CacheBackendInterface $cache)
  {
    $this->cache = $cache;
  }
  /**
   * Set Token Manager dependency
   */
  protected function setTokenManager(WebformTokenManagerInterface $token_manager)
  {
    $this->tokenManager = $token_manager;
  }

  /**
   * Set Sendinblue API dependency
   */
  protected function setSendinblueApi(SendinblueApi $sendinblueApi)
  {
    $this->sendinblueApi = $sendinblueApi;
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary()
  {
    $fields = $this->getWebform()->getElementsInitializedAndFlattened();
    $lists = $this->sendinblueApi->getMailingLists();

    $email_summary = $this->configuration['email_address'];
    if (!empty($fields[$this->configuration['email_address']])) {
      $email_summary = $fields[$this->configuration['email_address']]['#title'];
    }
    $email_summary = '<strong>' . $this->t('Email') . ': </strong>' . $email_summary;


    $list_summary = $this->configuration['list'];
    if (!empty($lists[$this->configuration['list']])) {
      $list_summary = $lists[$this->configuration['list']]->name;
    }
    $list_summary = '<strong>' . $this->t('List') . ': </strong>' . $list_summary;

    $markup = "$email_summary<br/>$list_summary";
    return [
      '#markup' => $markup,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration()
  {

    return [
      'list' => '',
      'email' => '',
      'mergevars' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state)
  {
    $enabled = $this->configFactory->get('sendinblue_api.enabled_lists')->getRawData();
    $lists = $this->sendinblueApi->getMailingLists();
    $custom_fields_data = $this->sendinblueApi->getCustomFields(false);
    $custom_fields = $custom_fields_data->custom_fields;

    $options = [];
    foreach ($lists as $list) {
      if ($enabled[$list->list_id] == 1) {
        $options[$list->list_id] = $list->name;
      }
    }

    $form['sendinblue_api'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Sendinblue API settings'),
      '#attributes' => ['id' => 'webform-sendinblue-api-handler-settings'],
    ];

    $form['sendinblue_api']['update'] = [
      '#type' => 'submit',
      '#value' => $this->t('Refresh lists & groups'),
      '#ajax' => [
        'callback' => [$this, 'ajaxSendinblueApiListHandler'],
        'wrapper' => 'webform-sendinblue-api-handler-settings',
      ],
      '#submit' => [[get_class($this), 'sendinblueUpdateConfigSubmit']],
    ];

    $form['sendinblue_api']['list'] = [
      '#type' => 'webform_select_other',
      '#title' => $this->t('List'),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select an option -'),
      '#default_value' => $this->configuration['list'],
      '#options' => $options,
      '#ajax' => [
        'callback' => [$this, 'ajaxSendinblueApiListHandler'],
        'wrapper' => 'webform-sendinblue-api-handler-settings',
      ],
      '#description' => $this->t('Select the list you want to send this submission to. Alternatively, you can also use the Other field for token replacement.'),
    ];

    $fields = $this->getWebform()->getElementsInitializedAndFlattened();
    $options = [];
    foreach ($fields as $field_name => $field) {
      // if (in_array($field['#type'], ['email', 'webform_email_confirm'])) {
      $options[$field_name] = $field['#title'];
      // }
    }

    $default_value = $this->configuration['email_address'];
    if (empty($this->configuration['email_address']) && count($options) == 1) {
      $default_value = key($options);
    }
    $form['sendinblue_api']['email_address'] = [
      '#type' => 'webform_select_other',
      '#title' => $this->t('Email field'),
      '#required' => TRUE,
      '#default_value' => $default_value,
      '#options' => $options,
      '#empty_option' => $this->t('- Select an option -'),
      '#description' => $this->t('Select the email element you want to use for subscribing to the Sendinblue API list specified above. Alternatively, you can also use the Other field for token replacement.'),
    ];

    // Custom Fields
    if (isset($custom_fields) && is_array($custom_fields) && count($custom_fields) > 0) {

      foreach ($custom_fields as $id => $values) {
        $field_name = $values->name;
        $default_value = $this->configuration[$field_name];

        $message = '';
        if ($field_name == 'SMS') {
          $message = 'Mobile Number in SMS field should be passed with proper country code. For example: Accepted Number Formats are 91xxxxxxxxxx, +91xxxxxxxxxx, 0091xxxxxxxxxx';
        }

        $form['sendinblue_api'][$field_name] = [
          '#type' => 'webform_select_other',
          '#title' => $this->t($field_name),
          '#default_value' => $default_value,
          '#options' => $options,
          '#empty_option' => $this->t('- Select an option -'),
          '#description' => $this->t('Select the ' . $field_name . ' element you want to use for subscribing to the Sendinblue API list specified above. Alternatively, you can also use the Other field for token replacement. ' . $message),
        ];
      }
    }

    $form['sendinblue_api']['mergevars'] = [
      '#type' => 'webform_codemirror',
      '#mode' => 'yaml',
      '#title' => $this->t('Merge vars'),
      '#default_value' => $this->configuration['mergevars'],
      '#description' => $this->t('You can map additional fields from your webform to fields in your Sendinblue API list, one per line. An example might be name: [webform_submission:values:name]. The result is sent as an array. You may use tokens.'),
    ];

    $form['sendinblue_api']['token_tree_link'] = $this->tokenManager->buildTreeLink();

    return $form;
  }

  /**
   * Ajax callback to update Webform Sendinblue API settings.
   */
  public static function ajaxSendinblueApiListHandler(array $form, FormStateInterface $form_state)
  {
    return $form['settings']['sendinblue_api'];
  }


  /**
   * Submit callback for the refresh button.
   */
  public static function sendinblueUpdateConfigSubmit(array $form, FormStateInterface $form_state)
  {
    // Trigger list and group category refetch by deleting lists cache.
    $cache = \Drupal::cache();
    $cache->delete('sendinblue_api.lists');
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
  {
    parent::submitConfigurationForm($form, $form_state);
    $values = $form_state->getValues();

    $components = $this->webform->getElementsInitializedAndFlattened();

    if (isset($values['sendinblue_api'])) {
      foreach ($values['sendinblue_api'] as $name => $value) {
        $this->configuration[$name] = $value;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave1(WebformSubmissionInterface $webform_submission, $update = TRUE)
  {
    // If update, do nothing
    if ($update) {
      return;
    }

    $fields = $webform_submission->toArray(TRUE);

    $configuration = $this->tokenManager->replace($this->configuration, $webform_submission);

    // Check if we are using a token for the list field & replace value.
    if (isset($this->configuration['list']) && strpos($this->configuration['list'], '[webform_submission:values:') !== false) {
      $configuration['list'] = null;
      $fieldToken = str_replace(['[webform_submission:values:', ']'], '', $this->configuration['list']);

      if (isset($fields['data'][$fieldToken])) {
        if (is_string($fields['data'][$fieldToken])) {
          $configuration['list'] = [$fields['data'][$fieldToken]];
        } else if (is_array($fields['data'][$fieldToken])) {
          $configuration['list'] = $fields['data'][$fieldToken];
        }
      }
    }

    $data = array();

    foreach ($configuration as $key => $value) {
      if ($value != null && $value != '') {
        if (in_array($key, array('list', 'email'))) {
          $data[$key] = $value;
        } else if (!isset($fields['data'][$value])) {
          $data['custom_fields'][$key] = $value;
        } else {
          $data['custom_fields'][$key] = $fields['data'][$value];
        }
      }
    }

    // Email could be a webform element or a string/token.
    if (!empty($fields['data'][$configuration['email_address']])) {
      $email = $fields['data'][$configuration['email_address']];
    } else {
      $email = $configuration['email_address'];
    }

    $data['email_address'] = $email;

    $mergevars = Yaml::decode($configuration['mergevars']) ?? [];

    // Allow other modules to alter the merge vars.
    // @see hook_sendinblue_api_lists_mergevars_alter().
    $entity_type = 'webform_submission';
    \Drupal::moduleHandler()->alter('sendinblue_api_lists_mergevars', $mergevars, $webform_submission, $entity_type);
    \Drupal::moduleHandler()->alter('webform_sendinblue_api_lists_mergevars', $mergevars, $webform_submission, $this);

    $handler_link = Link::createFromRoute(
      t('Edit handler'),
      'entity.webform.handler.edit_form',
      [
        'webform' => $this->getWebform()->id(),
        'webform_handler' => $this->getHandlerId(),
      ]
    )->toString();

    $submission_link = ($webform_submission->id()) ? $webform_submission->toLink($this->t('View'))->toString() : NULL;

    $context = [
      'link' => $submission_link . ' / ' . $handler_link,
      'webform_submission' => $webform_submission,
      'handler_id' => $this->getHandlerId(),
    ];

    if (!empty($configuration['list']) && !empty($email)) {
      $this->sendinblueApi->submitContactForm($data, $configuration['list']);
    } else {
      if (empty($configuration['list'])) {
        \Drupal::logger('webform_submission')->warning(
          'No Sendinblue API list was provided to the handler.',
          $context
        );
      }
      if (empty($email)) {
        \Drupal::logger('webform_submission')->warning(
          'No email address was provided to the handler.',
          $context
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission)
  {
    $this->validateSms($form_state, $webform_submission);
  }

  /**
   * Validate phone.
   */
  private function validateSms(FormStateInterface $formState, WebformSubmissionInterface $webform_submission)
  {

    $configuration = $this->configuration;
    $data = array();
    $fields = $formState->getValues();

    if (isset($configuration['SMS']) && $configuration['SMS'] != '') {
      $data = array();
      $sms_field = 'sms';
      foreach ($configuration as $key => $value) {
        if ($value != null && $value != '') {
          if (in_array($key, array('list'))) {
            $data[$key] = $value;
          } else if (!isset($fields[$value])) {
            $data['custom_fields'][$key] = $value;
          } else {
            $data['custom_fields'][$key] = $fields[$value];
          }
        }
      }

      if (isset($fields[$configuration['SMS']])) {
        $sms_field = $configuration['SMS'];
      }

      // Email could be a webform element or a string/token.
      if (!empty($fields['email'])) {
        $email = $fields['email'];
      } else {
        $email = $configuration['email_address'];
      }

      $data['email_address'] = $email;

      $response = $this->sendinblueApi->submitContactForm($data, $configuration['list']);

      if (isset($response['error'])) {
        $formState->setErrorByName($sms_field, $this->t($response['error']));
      }
    }
  }

}