<?php

namespace Drupal\sendinblue_api\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\OptGroup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\OptionsProviderInterface;

/**
 * Provides a field type for Sendinblue API Lists.
 * 
 * @FieldType(
 *   id = "sendinblue_api_lists",
 *   label = @Translation("Sendinblue API Lists"),
 *   category = @Translation("Sendinblue API"),
 *   default_widget = "sendinblue_api_lists_default",
 *   default_formatter = "sendinblue_api_lists_formatter",
 * )
 */

class SendinblueApiListItem extends FieldItemBase implements OptionsProviderInterface
{

  /**
   * Instantiate our service.
   * Doesn'ts eem to be able to inject in FieldType
   * @see https://drupal.stackexchange.com/questions/224247/how-do-i-inject-a-dependency-into-a-fieldtype-plugin
   *
   * @return Drupal\sendinblue_api\Service\SendinblueApi;
   */
  private function sendinblueApi()
  {
    return \Drupal::service('sendinblue_api');
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultStorageSettings()
  {
    return [
      'enabled_lists_only' => TRUE,
      'subscribe_on_save' => FALSE,
      'unsubscribe_on_delete' => FALSE,
      'field_mapping' => [
        'email_address' => NULL
      ]
    ] + parent::defaultStorageSettings();
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition)
  {
    return [
      'columns' => [
        'value' => [
          'type' => 'varchar',
          'length' => 255,
        ],
      ],
      'indexes' => [
        'value' => ['value'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition)
  {
    $properties['value'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('List UUID'))
      ->addConstraint('Length', ['max' => 255]);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty()
  {
    $value = $this->get('value')->getValue();
    return $value === NULL || $value === '';
  }

  /**
   * {@inheritdoc}
   */
  public function storageSettingsForm(array &$form, FormStateInterface $form_state, $has_data)
  {
    $element = parent::storageSettingsForm($form, $form_state, false);
    $entityType = $this->getEntity();
    $entityFields = $entityType->getFields();

    // Remove this for now since SendinblueApi service only allowes enabled lists.
    // $element['enabled_lists_only'] = [
    //   '#type' => 'checkbox',
    //   '#title' => $this->t('Allow only enabled lists to be selected'),
    //   '#default_value' => $this->getSetting('enabled_lists_only')
    // ];

    $element['subscribe_on_save'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Subscribe to the selected lists on entity save/create. Will require an email field to map the contact to.'),
      '#default_value' => $this->getSetting('subscribe_on_save')
    ];

    $element['unsubscribe_on_delete'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Unsubscribe to the selected lists on entity delete.'),
      '#description' => $this->t('Checking this will unsubscribe the contact from all lists upon entity delete. Will require an email field to map the contact to.'),
      '#default_value' => $this->getSetting('unsubscribe_on_delete'),
      '#states' => [
        'visible' => [':input[name="settings[subscribe_on_save]"]' => ['checked' => TRUE]]
      ]
    ];

    $element['field_mapping'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Entity Field Mapping to Sendinblue API Fields'),
      '#tree' => TRUE,
      '#states' => [
        'visible' => [':input[name="settings[subscribe_on_save]"]' => ['checked' => TRUE]]
      ]
    ];

    $element['field_mapping']['email_address'] = [
      '#type' => 'select',
      '#title' => $this->t('Email'),
      '#default_value' => isset($this->getSetting('field_mapping')['email_address']) ? $this->getSetting('field_mapping')['email_address'] : NULL,
      '#description' => $this->t('Requires a field of type <strong>email</strong>'),
      '#states' => [
        'required' => [':input[name="settings[subscribe_on_save]"]' => ['checked' => TRUE]]
      ]
    ];

    // Add field mapping options
    // @TODO - what to do if field is deleted.
    foreach ($entityFields as $fieldName => $fieldItemList) {
      $fieldDefinition = $fieldItemList->getFieldDefinition();
      $fieldLabel = $fieldDefinition->getLabel();
      $fieldType = $fieldDefinition->getType();

      if ($fieldType === 'email') {
        $element['field_mapping']['email_address']['#options'][$fieldName] = $fieldLabel;
      }
    }

    return $element;
  }


  /**
   * {@inheritdoc}
   */
  public function getPossibleValues(AccountInterface $account = NULL)
  {
    return $this->getSettableValues($account);
  }

  /**
   * {@inheritdoc}
   */
  public function getPossibleOptions(AccountInterface $account = NULL)
  {
    return $this->getSettableOptions($account);
  }


  /**
   * {@inheritdoc}
   */
  public function getSettableValues(AccountInterface $account = NULL)
  {
    // Flatten options first, because "settable options" may contain group
    // arrays.
    $flatten_options = OptGroup::flattenOptions($this->getSettableOptions($account));
    return array_keys($flatten_options);
  }

  public function getSettableOptions(AccountInterface $account = NULL)
  {
    $cc = $this->sendinblueApi();
    $options = [];

    // Remove this for now since SendinblueApi service only allowes enabled lists.
    if ($this->getSetting('enabled_lists_only') === 1) {
      $lists = $cc->getEnabledMailingLists();
    } else {
      $lists = $cc->getMailingLists();
    }

    if (is_array($lists)) {
      foreach ($lists as $id => $list) {
        $options[$id] = $list->name;
      }
    }

    return $options;
  }
}