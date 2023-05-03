<?php

namespace Drupal\sendinblue_api\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\sendinblue_api\Service\SendinblueApi;

/**
 * Provides a sendinblue api signup form per list that is enabled.
 *
 * @Block(
 *   id = "sendinblue_api",
 *   admin_label = @Translation("Sendinblue API Signup Form"),
 *   deriver = "Drupal\sendinblue_api\Plugin\Derivative\SendinblueApiBlockDerivative"
 * )
 */
class SendinblueApiBlock extends BlockBase implements ContainerFactoryPluginInterface
{

  /**
   * Drupal\Core\Form\FormBuilderInterface.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * Drupal\sendinblue_api\Service\SendinblueApi.
   *
   * @var \Drupal\sendinblue_api\Service\SendinblueApi
   *   Sendinblue api service.
   */
  protected $sendinblueApi;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, FormBuilderInterface $form_builder, SendinblueApi $sendinblueApi)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->formBuilder = $form_builder;
    $this->sendinblueApi = $sendinblueApi;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
  {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('form_builder'),
      $container->get('sendinblue_api')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state)
  {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();
    $ccConfig = $this->sendinblueApi->getConfig();
    $customFields = $this->sendinblueApi->getCustomFields();

    $form['cc_fields'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Add Form Fields from Sendinblue API'),
      '#description' => $this->t('Select any <a href="https://developers.sendinblue.com" target="_blank" rel="nofollow noreferrer">available fields from Sendinblue API</a> to add to the signup form.'),
    ];

    foreach ($ccConfig['fields'] as $fieldName => $fieldLabel) {
      $form['cc_fields']['field_' . $fieldName] = [
        '#type' => 'fieldset',
        '#title' => $this->t($fieldLabel),
      ];

      $form['cc_fields']['field_' . $fieldName][$fieldName] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Display ' . $fieldLabel . ' field?'),
        '#default_value' => isset($config[$fieldName]) ? $config[$fieldName] : 0,
      ];
      $form['cc_fields']['field_' . $fieldName][$fieldName . '_required'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Require ' . $fieldLabel . ' field?'),
        '#default_value' => isset($config[$fieldName . '_required']) ? $config[$fieldName . '_required'] : 0,
      ];
    }

    // Custom Fields
    if ($customFields && $customFields->custom_fields && is_array($customFields->custom_fields) && count($customFields->custom_fields) > 0) {
      $form['custom_fields'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Custom Fields'),
        '#description' => $this->t('Select any <a href="https://developers.sendinblue.com" target="_blank" rel="nofollow noreferrer">custom fields from your Sendinblue API account</a> to add to the signup form.'),
        '#tree' => true
      ];

      foreach ($customFields->custom_fields as $field) {
        $attributes = array();
        $field_required = false;

        if (isset($field->is_required) && $field->is_required) {
          $field_required = true;
          $attributes = array('disabled' => 'disabled');

        } else if (isset($config['custom_fields'][$field->custom_field_id]['required'])) {
          $field_required = $config['custom_fields'][$field->custom_field_id]['required'];
        }

        $form['custom_fields'][$field->custom_field_id] = [
          '#type' => 'fieldset',
          '#title' => $this->t($field->label),
        ];
        $form['custom_fields'][$field->custom_field_id]['display'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Display ' . $field->label . ' field'),
          '#default_value' => isset($config['custom_fields'][$field->custom_field_id]['display']) ? $config['custom_fields'][$field->custom_field_id]['display'] : NULL,
        ];
        $form['custom_fields'][$field->custom_field_id]['required'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Require ' . $field->label . ' field'),
          '#default_value' => $field_required,
          '#attributes' => $attributes,
        ];

        $form['custom_fields'][$field->custom_field_id]['name'] = [
          '#type' => 'hidden',
          '#value' => $field->name,
        ];
        $form['custom_fields'][$field->custom_field_id]['type'] = [
          '#type' => 'hidden',
          '#value' => $field->type,
        ];
        $form['custom_fields'][$field->custom_field_id]['label'] = [
          '#type' => 'hidden',
          '#value' => $field->label,
        ];

        if ($field->type === 'date') {
          $form['custom_fields'][$field->custom_field_id]['#description'] = $this->t('Requires the <a href="https://www.drupal.org/docs/8/core/modules/datetime" target="_blank" rel="nofollow noreferrer">datetime</a> module to be installed.');
        }
      }
    }

    $form['body'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Body'),
      '#default_value' => isset($config['body']) ? $config['body']['value'] : NULL,
      '#format' => isset($config['format']) ? $config['body']['format'] : NULL,
    ];
    $form['success_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom Success Message'),
      '#default_value' => isset($config['success_message']) ? $config['success_message'] : NULL,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state)
  {
    parent::blockSubmit($form, $form_state);

    $values = $form_state->getValues();
    $ccConfig = $this->sendinblueApi->getConfig();

    foreach ($ccConfig['fields'] as $fieldName => $fieldLabel) {
      if (isset($values['cc_fields']['field_' . $fieldName][$fieldName])) {
        $this->configuration[$fieldName] = $values['cc_fields']['field_' . $fieldName][$fieldName];
      }

      if (isset($values['cc_fields']['field_' . $fieldName][$fieldName . '_required'])) {
        $this->configuration[$fieldName . '_required'] = $values['cc_fields']['field_' . $fieldName][$fieldName . '_required'];
      }
    }

    $this->configuration['custom_fields'] = $values['custom_fields'];
    $this->configuration['body'] = $values['body'];
    $this->configuration['success_message'] = $values['success_message'];
  }

  /**
   * {@inheritdoc}
   */
  public function build()
  {
    $listConfig = $this->getConfiguration();
    $listConfig['list_id'] = str_replace('sendinblue_api:', '', $this->getPluginId());

    return $this->formBuilder->getForm('Drupal\sendinblue_api\Form\SendinblueApiBlockForm', $listConfig);
  }

}