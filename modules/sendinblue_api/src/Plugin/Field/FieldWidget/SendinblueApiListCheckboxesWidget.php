<?php

namespace Drupal\sendinblue_api\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsButtonsWidget;

/**
 * Plugin implementation of the 'sendinblue_api_lists_default' widget.
 *
 * @FieldWidget(
 *   id = "sendinblue_api_lists_checkbox",
 *   label = @Translation("Check boxes/radio buttons"),
 *   field_types = {
 *     "sendinblue_api_lists"
 *   },
 *   multiple_values = TRUE
 * )
 */
class SendinblueApiListCheckboxesWidget extends OptionsButtonsWidget
{
}