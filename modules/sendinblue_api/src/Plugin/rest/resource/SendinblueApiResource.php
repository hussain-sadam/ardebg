<?php

namespace Drupal\sendinblue_api\Plugin\rest\resource;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\sendinblue_api\Service\SendinblueApi;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ModifiedResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Provides a Sendinblue API API Resource.
 *
 * @RestResource(
 *   id = "sendinblue_api_resource",
 *   label = @Translation("Sendinblue API Resource"),
 *   uri_paths = {
 *     "create" = "/sendinblue_api/{list_id}"
 *   }
 * )
 */
class SendinblueApiResource extends ResourceBase
{

  /**
   * Drupal\Core\Path\CurrentPathStack.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected $currentPath;

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
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, array $serializer_formats, LoggerInterface $logger, CurrentPathStack $current_path, ConfigFactoryInterface $config_factory, SendinblueApi $sendinblueApi)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->currentPath = $current_path;
    $this->configFactory = $config_factory;
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
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('sendinblue_api'),
      $container->get('path.current'),
      $container->get('config.factory'),
      $container->get('sendinblue_api')
    );
  }

  /**
   * Responds to entity POST requests.
   *
   * Takes the post request and sends it
   * to Sendinblue API API endpoints.
   * @param string $list_id
   *   CC list (list_id). Can be an array of list uuids.
   *
   * @param array $data
   *   Form data.
   *
   * @return \Drupal\rest\ModifiedResourceResponse
   *   The HTTP response object.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws HttpException in case of error.
   */
  public function post($list_id, array $data)
  {
    $enabledLists = $this->configFactory->get('sendinblue_api.enabled_lists')->getRawData();
    $lists = [];

    if (is_array($list_id)) {
      foreach ($list_id as $lid) {
        if (!isset($enabledLists[$lid]) || $enabledLists[$lid] !== 1) {
          throw new AccessDeniedHttpException('This list is not enabled or does not exist.');
        } else {
          $lists[] = $lid;
        }
      }
    } else if (is_string($list_id)) {
      if (!isset($enabledLists[$list_id]) || $enabledLists[$list_id] !== 1) {
        throw new AccessDeniedHttpException('This endpoint is not enabled or does not exist.');
      } else {
        $lists[] = $list_id;
      }
    }

    $response = $this->sendinblueApi->submitContactForm($data, [$list_id]);
    return new ModifiedResourceResponse($response);
  }
}