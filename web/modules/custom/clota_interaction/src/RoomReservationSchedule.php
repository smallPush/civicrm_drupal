<?php

namespace Drupal\clota_interaction;

use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

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
   * Builds a monthly calendar containing every room reservation.
   */
  public function buildMonthlyCalendar(?string $requestedMonth = NULL): array {
    $timezone = new \DateTimeZone('Europe/Madrid');
    $today = new \DateTimeImmutable('today', $timezone);
    $month = $today->modify('first day of this month');
    if ($requestedMonth && preg_match('/^\d{4}-\d{2}$/', $requestedMonth)) {
      $candidate = \DateTimeImmutable::createFromFormat('!Y-m', $requestedMonth, $timezone);
      if ($candidate && $candidate->format('Y-m') === $requestedMonth) {
        $month = $candidate;
      }
    }

    $nextMonth = $month->modify('first day of next month');
    $reservations = $this->database->select('clota_room_reservation', 'r')
      ->fields('r', ['uid', 'start_at', 'end_at'])
      ->condition('start_at', $month->getTimestamp(), '>=')
      ->condition('start_at', $nextMonth->getTimestamp(), '<')
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

    $events = [];
    foreach ($reservations as $reservation) {
      $uid = (int) $reservation->uid;
      $day = $this->dateFormatter->format((int) $reservation->start_at, 'custom', 'Y-m-d', 'Europe/Madrid');
      $events[$day][] = [
        'time' => $this->dateFormatter->format((int) $reservation->start_at, 'custom', 'H:i', 'Europe/Madrid') . ' - ' .
          $this->dateFormatter->format((int) $reservation->end_at, 'custom', 'H:i', 'Europe/Madrid'),
        'user' => isset($users[$uid]) ? $users[$uid]->getDisplayName() : (string) $this->t('Usuari eliminat'),
      ];
    }

    $cells = array_fill(0, (int) $month->format('N') - 1, [
      'data' => ['#markup' => ''],
      'class' => ['clota-calendar__day--empty'],
    ]);
    $daysInMonth = (int) $month->format('t');
    for ($dayNumber = 1; $dayNumber <= $daysInMonth; $dayNumber++) {
      $date = $month->setDate((int) $month->format('Y'), (int) $month->format('m'), $dayNumber);
      $dateKey = $date->format('Y-m-d');
      $content = [
        '#type' => 'container',
        '#attributes' => ['class' => ['clota-calendar__day']],
        'number' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => (string) $dayNumber,
          '#attributes' => ['class' => ['clota-calendar__day-number']],
        ],
      ];
      if (!empty($events[$dateKey])) {
        $content['events'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['clota-calendar__events']],
        ];
        foreach ($events[$dateKey] as $index => $event) {
          $content['events']['event_' . $index] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['clota-calendar__event']],
            'time' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => Html::escape($event['time']),
              '#attributes' => ['class' => ['clota-calendar__event-time']],
            ],
            'user' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => Html::escape($event['user']),
              '#attributes' => ['class' => ['clota-calendar__event-user']],
            ],
          ];
        }
      }

      $classes = [];
      if ($dateKey === $today->format('Y-m-d')) {
        $classes[] = 'clota-calendar__day--today';
      }
      $cells[] = ['data' => $content, 'class' => $classes];
    }
    while (count($cells) % 7 !== 0) {
      $cells[] = [
        'data' => ['#markup' => ''],
        'class' => ['clota-calendar__day--empty'],
      ];
    }

    $rows = [];
    foreach (array_chunk($cells, 7) as $week) {
      $rows[] = $week;
    }

    $previous = $month->modify('-1 month')->format('Y-m');
    $next = $month->modify('+1 month')->format('Y-m');
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['clota-calendar']],
      'toolbar' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['clota-calendar__toolbar']],
        'previous' => Link::fromTextAndUrl(
          $this->t('‹ Mes anterior'),
          Url::fromRoute('clota_interaction.room_reservation', [], [
            'query' => ['mes' => $previous],
            'attributes' => ['class' => ['clota-calendar__previous']],
          ]),
        )->toRenderable(),
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->dateFormatter->format($month->getTimestamp(), 'custom', 'F Y', 'Europe/Madrid'),
          '#attributes' => ['class' => ['clota-calendar__title']],
        ],
        'next' => Link::fromTextAndUrl(
          $this->t('Mes següent ›'),
          Url::fromRoute('clota_interaction.room_reservation', [], [
            'query' => ['mes' => $next],
            'attributes' => ['class' => ['clota-calendar__next']],
          ]),
        )->toRenderable(),
      ],
      'viewport' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['clota-calendar__viewport']],
        'grid' => [
          '#type' => 'table',
          '#attributes' => ['class' => ['clota-calendar__grid']],
          '#header' => [
            $this->t('Dilluns'),
            $this->t('Dimarts'),
            $this->t('Dimecres'),
            $this->t('Dijous'),
            $this->t('Divendres'),
            $this->t('Dissabte'),
            $this->t('Diumenge'),
          ],
          '#rows' => $rows,
        ],
      ],
      '#attached' => ['library' => ['clota_interaction/room_calendar']],
      '#cache' => [
        'contexts' => ['url.query_args:mes', 'user.roles'],
        'max-age' => 0,
      ],
    ];
  }

}
