<?php

namespace Drupal\clota_interaction\Plugin\Block;

use Drupal\clota_interaction\RoomReservationSchedule;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays all future Clota room reservations.
 *
 * @Block(
 *   id = "clota_room_reservations",
 *   admin_label = @Translation("Clota room reservations"),
 *   category = @Translation("Clota")
 * )
 */
final class RoomReservationsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly RoomReservationSchedule $schedule,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('clota_interaction.room_reservations'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'reserve clota room');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return $this->schedule->buildFutureReservations();
  }

}
