<?php

/**
 * @file
 * Hooks provided by the Sendinblue API module.
 */

use Drupal\webform\Plugin\WebformHandlerInterface;
use Drupal\webform\WebformSubmissionInterface;

/**
 *
 * For example if you would like to send custom fields.
 *
 * @see https://developers.sendinblue.com
 *
 */

/**
 * hook_sendinblue_api_contact_data_alter
 *
 * Alters the contact data that is sent to Sendinblue API.
 * This hook is triggered on both create and update of contacts.
 *
 * @param array $data - The data received from a sendinblue api form.
 * @param object $body - The content of the request that is sent to the Sendinblue API API
 * @return void
 */
function hook_sendinblue_api_contact_data_alter(array $data, object &$body)
{
  // Add custom field values
  // Set a value directly.
  $body->custom_fields[] = (object) [
    'custom_field_id' => '00000000-0000-0000-0000-0000000000',
    // The UUID of the custom field
    'value' => 'Some Value', // A value.
  ];

  // Add custom field values
  // Set based on $data value.
  $body->custom_fields[] = (object) [
    'custom_field_id' => '00000000-0000-0000-0000-0000000000',
    // The UUID of the custom field
    'value' => $data['company'], // A value.
  ];

}

/**
 * Alter mergevars before they are sent to Sendinblue API.
 *
 * @param array $mergevars
 *   The current mergevars.
 * @param WebformSubmissionInterface $submission
 *   The webform submission entity used to populate the mergevars.
 * @param WebformHandlerInterface $handler
 *   The webform submission handler used to populate the mergevars.
 *
 * @ingroup webform_sendinblue_api
 */
function hook_sendinblue_api_lists_mergevars_alter(&$mergevars, WebformSubmissionInterface $submission, WebformHandlerInterface $handler)
{

}