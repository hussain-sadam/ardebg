<?php

namespace Drupal\sendinblue_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\sendinblue_api\Service\SendinblueApi;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines CustomFieldsController class.
 */
class CustomFieldsController extends ControllerBase
{

  /**
   * Constructor function.
   *
   * @param \Drupal\sendinblue_api\Service\SendinblueApi $sendinblueApi
   *   Sendinblue api service.
   */
  public function __construct(SendinblueApi $sendinblueApi)
  {
    $this->sendinblueApi = $sendinblueApi;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('sendinblue_api')
    );
  }

  /**
   * Display the markup.
   *
   * @return array
   *   Return markup array.
   */
  public function content()
  {
    $fields = $this->sendinblueApi->getCustomFields(false);
    $header = ['Custom Field Name', 'Field Type', 'Custom Field ID'];
    $rows = [];

    if ($fields && is_array($fields->custom_fields) && count($fields->custom_fields) > 0) {
      $markup = $this->t('Custom fields available for your account:') . '<ul>';
      foreach ($fields->custom_fields as $field) {
        $rows[] = [
          $this->t($field->label),
          $this->t($field->type),
          [
            'data' => [
              '#markup' => '<code>' . $field->custom_field_id . '</code>'
            ]
          ]
        ];
      }

    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('There are no custom fields found.')
    ];
  }

}