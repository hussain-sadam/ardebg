<?php

namespace Drupal\sendinblue_api\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\sendinblue_api\Service\SendinblueApi;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Class SendinblueApiBlockForm.
 *
 * Creates a form for block on frontend to post
 * contact info and send to Sendinblue API.
 */
class SendinblueApiBlockForm extends FormBase
{
  /**
   * {@inheritdoc}
   */
  protected $formIdentifier;

  /**
   * Drupal\Core\Messenger\MessengerInterface.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   *   Messenger Interface.
   */
  protected $messenger;

  /**
   * Drupal\sendinblue_api\Service\SendinblueApi.
   *
   * @var \Drupal\sendinblue_api\Service\SendinblueApi
   *   Sendinblue api service.
   */
  protected $sendinblueApi;

  /**
   * \Drupal\Core\Extension\ModuleHandlerInterface
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   *   Module handler interface
   */
  protected $moduleHandler;

  /**
   * SendinblueApiBlockForm constructor.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   MessengerInterface.
   * @param \Drupal\sendinblue_api\Service\SendinblueApi $sendinblueApi
   *   Sendinblue api service.
   */
  public function __construct(MessengerInterface $messenger, SendinblueApi $sendinblueApi, ModuleHandlerInterface $moduleHandler)
  {
    $this->messenger = $messenger;
    $this->sendinblueApi = $sendinblueApi;
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('messenger'),
      $container->get('sendinblue_api'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setFormIdentifier($formIdentifier)
  {
    $this->formIdentifier = $formIdentifier;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    $form_id = 'sendinblue_api_sigup_form';
    if ($this->formIdentifier) {
      $form_id .= '-' . $this->formIdentifier;
    }

    return $form_id;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $listConfig = [])
  {
    // Don't show anything if we don't have a list_id set.
    if (!isset($listConfig['list_id'])) {
      return NULL;
    }

    if (isset($listConfig['success_message']) && $listConfig['success_message']) {
      $form_state->set('success_message', $listConfig['success_message']);
    }

    if (isset($listConfig['body']) && isset($listConfig['body']['value'])) {
      $form['body'] = [
        '#markup' => $listConfig['body']['value'],
      ];
    }

    if (isset($listConfig['name']) && $listConfig['name'] === 1) {
      $form['name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Name'),
        '#required' => isset($listConfig['name_required']) && $listConfig['name_required'] === 1,
      ];
    }

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email Address'),
      '#required' => TRUE,
    ];

    $otherFields = $this->sendinblueApi->getConfig()['fields'];

    foreach ($otherFields as $fieldName => $fieldLabel) {
      if (!in_array($fieldName, ['name'])) {
        if (isset($listConfig[$fieldName]) && $listConfig[$fieldName] === 1) {
          $form[$fieldName] = [
            '#type' => 'textfield',
            '#title' => $this->t($fieldLabel),
            '#required' => isset($listConfig[$fieldName . '_required']) && $listConfig[$fieldName . '_required'] === 1,
          ];
        }
      }
    }


    // Custom Fields
    if (isset($listConfig['custom_fields']) && is_array($listConfig['custom_fields']) && count($listConfig['custom_fields']) > 0) {

      foreach ($listConfig['custom_fields'] as $id => $values) {

        if ($values['display'] == 1) {

          switch ($values['type']) {
            case 'date':
              $form['custom_field__' . $values['name']] = [
                '#type' => 'date',
                '#title' => $this->t($values['label']),
                '#required' => $values['required'] === 1,
                '#date_date_format' => 'Y-m-d',
              ];
              break;

            case 'select':
              $custom_fields_data = $this->sendinblueApi->getCustomFields(false);
              $custom_fields = $custom_fields_data->custom_fields;
              $obj = json_decode(json_encode($custom_fields, true), true);
              $options = array();
              $key = array_search($values['label'], array_column($obj, 'name'));
              if ($key) {
                $options = $obj[$key]['options'] ?? [];
              }

              $form['custom_field__' . $values['name']] = [
                '#type' => 'select',
                '#title' => $this->t($values['label']),
                '#required' => $values['required'] === 1,
                '#options' => $options,
              ];
              break;

            case 'number':
              $form['custom_field__' . $values['name']] = [
                '#type' => 'number',
                '#title' => $this->t($values['label']),
                '#required' => $values['required'] === 1,
              ];
              break;

            default:
              $form['custom_field__' . $values['name']] = [
                '#type' => 'textfield',
                '#title' => $this->t($values['label']),
                '#required' => $values['required'] === 1,
              ];
              break;
          }
        }
      }
    }

    // Add our list_id into the form.
    if ($listConfig['list_id'] === 'sendinblue_api_multi') {
      $list_ids = array_filter(array_values($listConfig['lists']));
      if (is_array($list_ids)) {
        $options = [];

        // Need at least one list_id
        if (is_array($list_ids) && is_array($list_ids) && count($list_ids) === 0) {
          return null;
        }

        foreach ($list_ids as $id) {
          $options[$id] = $listConfig['lists_all'][$id]->name;
        }

        if ($listConfig['lists_user_select'] === 1) {
          $form['list_id'] = [
            '#type' => 'checkboxes',
            '#title' => isset($listConfig['lists_select_label']) ? $listConfig['lists_select_label'] : $this->t('Sign me up for:'),
            '#required' => true,
            '#options' => $options
          ];
        } else {
          $form['list_id'] = [
            '#type' => 'value',
            '#value' => $list_ids,
          ];
        }
      }
    } else {
      $form['list_id'] = [
        '#type' => 'value',
        '#value' => $listConfig['list_id'],
      ];
    }

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sign Up'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $values = $form_state->getValues();
    $message_type = 'status';

    $otherFields = $this->sendinblueApi->getConfig()['fields'];

    $data = [
      'email_address' => $values['email'],
    ];

    foreach ($otherFields as $field => $label) {
      if (isset($values[$field]) && $values[$field]) {
        $data[$field] = $values[$field];
      }
    }


    // Add custom field values.
    // Skip adding it if there's no value.
    $fieldKeys = array_keys($values);
    foreach ($fieldKeys as $field) {
      if (strpos($field, 'custom_field__') !== false && isset($values[$field]) && $values[$field]) {
        $data['custom_fields'][str_replace('custom_field__', '', $field)] = $values[$field];
      }
    }

    $lists = [];

    if (is_string($values['list_id'])) {
      $lists = [$values['list_id']];
    } else {
      $lists = array_filter(array_values($values['list_id']));
    }

    $response = $this->sendinblueApi->submitContactForm($data, $lists);

    if (isset($response['error'])) {
      $message = 'There was a problem signing you up. Please try again later.';
      $message_type = 'error';
    } else {
      if ($form_state->get('success_message')) {
        $message = $form_state->get('success_message');
      } else {
        $message = $this->t('You have been signed up. Thank you.');
      }
    }

    $this->messenger->addMessage(strip_tags($message), $message_type);
  }

}