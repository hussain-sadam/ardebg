<?php

namespace Drupal\sendinblue_api\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\sendinblue_api\Service\SendinblueApi;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides block plugin definitions for Sendinblue API blocks.
 *
 * @see \Drupal\sendinblue_api\Plugin\Block\SendinblueApiBlock
 */
class SendinblueApiBlockDerivative extends DeriverBase implements ContainerDeriverInterface
{

  /**
   * Drupal\Core\Config\ConfigFactoryInterface.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Drupal\sendinblue_api\Service\SendinblueApi.
   *
   * @var \Drupal\sendinblue_api\Service\SendinblueApi
   *   Sendinblue api service.
   */
  protected $sendinblueApi;

  /**
   * SendinblueApiBlockDerivative constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   ConfigFactoryInterface.
   * @param \Drupal\sendinblue_api\Service\SendinblueApi $sendinblueApi
   *   Sendinblue api service.
   */
  public function __construct(ConfigFactoryInterface $configFactory, SendinblueApi $sendinblueApi)
  {
    $this->configFactory = $configFactory;
    $this->sendinblueApi = $sendinblueApi;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id)
  {
    return new static(
      $container->get('config.factory'),
      $container->get('sendinblue_api')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition)
  {
    $lists = $this->sendinblueApi->getEnabledMailingLists(false);

    if ($lists) {
      foreach ($lists as $id => $value) {
        if ($value->enabled === true) {
          $this->derivatives[$id] = $base_plugin_definition;
          $this->derivatives[$id]['admin_label'] = $lists[$id]->name . '  Signup Block';
        }
      }
    }

    return $this->derivatives;
  }

}