<?php

namespace Drupal\sendinblue_api\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsSelectWidget;

/**
 * Plugin implementation of the 'sendinblue_api_lists_default' widget.
 *
 * @FieldWidget(
 *   id = "sendinblue_api_lists_default",
 *   label = @Translation("Select list"),
 *   field_types = {
 *     "sendinblue_api_lists"
 *   },
 *   multiple_values = TRUE
 * )
 */
class SendinblueApiListDefaultWidget extends OptionsSelectWidget
{
}