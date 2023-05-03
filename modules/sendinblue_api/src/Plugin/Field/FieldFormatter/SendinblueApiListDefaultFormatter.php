<?php

namespace Drupal\sendinblue_api\Plugin\Field\FieldFormatter;

use Drupal\options\Plugin\Field\FieldFormatter\OptionsDefaultFormatter;

/**
 * Plugin implementation of the 'sendinblue_api_lists_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "sendinblue_api_lists_formatter",
 *   label = @Translation("Default"),
 *   field_types = {
 *     "sendinblue_api_lists",
 *   }
 * )
 */
class SendinblueApiListDefaultFormatter extends OptionsDefaultFormatter
{
}