<?php

namespace Drupal\sendinblue_api\Form;

use Drupal\sendinblue_api\Service\SendinblueApi;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class SendinblueApiLists.
 *
 * Configuration form for enabling lists for use.
 * (ex: in either blocks or REST endpoints.)
 */
class SendinblueApiLists extends ConfigFormBase
{

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
   * SendinblueApiLists constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Drupal\Core\Config\ConfigFactoryInterface.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Drupal\Core\Messenger\MessengerInterface.
   * @param \Drupal\sendinblue_api\Service\SendinblueApi $sendinblueApi
   *   Sendinblue api service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, MessengerInterface $messenger, SendinblueApi $sendinblueApi)
  {
    parent::__construct($config_factory);
    $this->messenger = $messenger;
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
      $container->get('sendinblue_api')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'sendinblue_api_lists';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames()
  {
    return [
      'sendinblue_api.enabled_lists',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $config = $this->sendinblueApi->getConfig();
    $enabled = $this->config('sendinblue_api.enabled_lists')->getRawData();

    $header = [
      'name' => $this->t('List Name'),
      'list_id' => $this->t('List UUID'),
    ];

    $output = $defaultValue = [];

    if (isset($config['api_key'])) {
      $lists = $this->sendinblueApi->getMailingLists();

      if (is_array($lists)) {
        if ($lists && is_array($lists) && count($lists) > 0) {
          foreach ($lists as $list) {
            $output[$list->list_id] = [
              'name' => $list->name,
              'list_id' => $list->list_id,
            ];

            $defaultValue[$list->list_id] = isset($enabled[$list->list_id]) && $enabled[$list->list_id] === 1 ? $list->list_id : NULL;
          }
        }
      }
    }

    $form['lists'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $output,
      '#default_value' => $defaultValue,
      '#empty' => $this->t('You must authorize Sendinblue API before enabling a list or there are no lists available on your account.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $config = $this->config('sendinblue_api.enabled_lists');
    $config->clear('sendinblue_api.enabled_lists');
    $enabled = $form_state->getValues()['lists'];
    $lists = $this->sendinblueApi->getMailingLists();

    foreach ($enabled as $key => $value) {
      $config->set($key, ($value === 0 ? 0 : 1));
    }

    $config->save();

    $this->sendinblueApi->saveContactLists($lists);

    $this->messenger->addMessage($this->t('Your configuration has been saved'));
  }

}