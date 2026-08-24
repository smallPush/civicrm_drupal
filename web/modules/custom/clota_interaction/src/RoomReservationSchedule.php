<?php

namespace Drupal\clota_interaction;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Queries and renders room reservations.
 */
final class RoomReservationSchedule {

  use StringTranslationTrait;

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Returns whether a reservation overlaps an existing reservation.
   */
  public function hasOverlap(int $start, int $end): bool {
    return (bool) $this->database->select('clota_room_reservation', 'r')
      ->fields('r', ['id'])
      ->condition('start_at', $end, '<')
      ->condition('end_at', $start, '>')
      ->range(0, 1)
      ->execute()
      ->fetchField();
  }

  /**
   * Builds a table with all future room reservations.
   */
  public function buildFutureReservations(): array {
    $reservations = $this->database->select('clota_room_reservation', 'r')
      ->fields('r', ['uid', 'start_at', 'end_at'])
      ->condition('end_at', \Drupal::time()->getRequestTime(), '>=')
      ->orderBy('start_at')
      ->execute()
      ->fetchAll();

    $uids = array_values(array_unique(array_map(
      static fn (object $reservation): int => (int) $reservation->uid,
      $reservations,
    )));
    $users = $uids
      ? $this->entityTypeManager->getStorage('user')->loadMultiple($uids)
      : [];

    $rows = [];
    foreach ($reservations as $reservation) {
      $uid = (int) $reservation->uid;
      $rows[] = [
        $this->dateFormatter->format((int) $reservation->start_at, 'custom', 'd/m/Y', 'Europe/Madrid'),
        $this->dateFormatter->format((int) $reservation->start_at, 'custom', 'H:i', 'Europe/Madrid') . ' - ' .
          $this->dateFormatter->format((int) $reservation->end_at, 'custom', 'H:i', 'Europe/Madrid'),
        isset($users[$uid]) ? $users[$uid]->getDisplayName() : $this->t('Usuari eliminat'),
      ];
    }

    return [
      '#type' => 'table',
      '#caption' => $this->t('Properes reserves'),
      '#header' => [
        $this->t('Data'),
        $this->t('Hora'),
        $this->t('Reservada per'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No hi ha cap reserva futura.'),
      '#cache' => ['max-age' => 0],
    ];
  }

}
