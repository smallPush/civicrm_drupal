<?php

namespace Drupal\clota_interaction\Controller;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Displays and filters public CiviCRM events.
 */
final class ClotaAgendaController extends ControllerBase {

  /**
   * Builds the upcoming or past event listing.
   */
  public function build(string $period = 'upcoming'): array {
    \Drupal::service('civicrm')->initialize();

    $request = \Drupal::request();
    $search = trim((string) $request->query->get('q', ''));
    $selectedType = max(0, (int) $request->query->get('type', 0));
    $from = $this->validDate((string) $request->query->get('from', ''));
    $to = $this->validDate((string) $request->query->get('to', ''));
    $typeLabels = $this->eventTypeLabels();
    $roomField = $this->roomFieldKey();

    $result = civicrm_api3('Event', 'get', [
      'is_active' => 1,
      'is_public' => 1,
      'return' => [
        'id',
        'title',
        'summary',
        'start_date',
        'end_date',
        'event_type_id',
        'is_online_registration',
        $roomField,
      ],
      'options' => ['limit' => 0],
      'check_permissions' => FALSE,
    ]);

    $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid'));
    $events = array_filter($result['values'], function (array $event) use ($period, $now, $search, $selectedType, $from, $to): bool {
      $start = new \DateTimeImmutable($event['start_date'], new \DateTimeZone('Europe/Madrid'));
      if (($period === 'past') !== ($start < $now)) {
        return FALSE;
      }
      if ($selectedType && (int) $event['event_type_id'] !== $selectedType) {
        return FALSE;
      }
      if ($from && $start < $from->setTime(0, 0)) {
        return FALSE;
      }
      if ($to && $start > $to->setTime(23, 59, 59)) {
        return FALSE;
      }
      if ($search !== '') {
        $haystack = mb_strtolower($event['title'] . ' ' . strip_tags((string) ($event['summary'] ?? '')));
        if (!str_contains($haystack, mb_strtolower($search))) {
          return FALSE;
        }
      }
      return TRUE;
    });

    uasort($events, static function (array $a, array $b) use ($period): int {
      $comparison = strcmp($a['start_date'], $b['start_date']);
      return $period === 'past' ? -$comparison : $comparison;
    });

    $routeName = $period === 'past' ? 'clota_interaction.agenda_history' : 'clota_interaction.agenda';
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['clota-agenda']],
      '#attached' => ['library' => ['clota_interaction/agenda']],
      '#cache' => [
        'contexts' => ['url.path', 'url.query_args'],
        'max-age' => 300,
      ],
      'intro' => [
        '#markup' => '<p class="clota-agenda__intro">' . $this->t('Tallers, trobades i activitats per compartir coneixement i fer comunitat.') . '</p>',
      ],
      'tabs' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['clota-agenda-tabs'], 'aria-label' => $this->t('Seccions de l’agenda')],
        'upcoming' => Link::fromTextAndUrl($this->t('Pròxims esdeveniments'), Url::fromRoute('clota_interaction.agenda'))->toRenderable(),
        'past' => Link::fromTextAndUrl($this->t('Històric'), Url::fromRoute('clota_interaction.agenda_history'))->toRenderable(),
      ],
      'filters' => $this->filters($routeName, $search, $selectedType, $from, $to, $typeLabels),
      'count' => [
        '#markup' => '<p class="clota-agenda__count">' . $this->translation()->formatPlural(count($events), '1 activitat', '@count activitats') . '</p>',
      ],
      'list' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['clota-agenda-list']],
      ],
    ];

    foreach ($events as $event) {
      $id = (int) $event['id'];
      $start = new \DateTimeImmutable($event['start_date'], new \DateTimeZone('Europe/Madrid'));
      $end = new \DateTimeImmutable($event['end_date'] ?: $event['start_date'], new \DateTimeZone('Europe/Madrid'));
      $register = $period !== 'past' && !empty($event['is_online_registration']);
      $url = Url::fromUserInput($register ? '/civicrm/event/register' : '/civicrm/event/info', [
        'query' => ['reset' => 1, 'id' => $id],
      ]);
      $typeId = (int) $event['event_type_id'];
      $build['list']['event_' . $id] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['clota-agenda-card']],
        'meta' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['clota-agenda-card__meta']],
          'type' => [
            '#markup' => '<span class="clota-agenda-card__badge">' . Xss::filter($typeLabels[$typeId] ?? $this->t('Activitat')) . '</span>',
          ],
          'date' => [
            '#markup' => '<time datetime="' . $start->format(DATE_ATOM) . '">' . $start->format('d/m/Y · H:i') . '–' . $end->format('H:i') . '</time>',
          ],
        ],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          'link' => Link::fromTextAndUrl($event['title'], $url)->toRenderable(),
        ],
        'summary' => [
          '#markup' => Xss::filter((string) ($event['summary'] ?? ''), ['p', 'strong', 'em', 'br']),
          '#prefix' => '<div class="clota-agenda-card__summary">',
          '#suffix' => '</div>',
        ],
        'footer' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['clota-agenda-card__footer']],
          'room' => [
            '#markup' => '<span class="clota-agenda-card__room">' . Xss::filter((string) ($event[$roomField] ?? 'La Clota')) . '</span>',
          ],
          'link' => Link::fromTextAndUrl($register ? $this->t('Inscriu-t’hi') : $this->t('Consulta l’activitat'), $url)->toRenderable(),
        ],
      ];
    }

    if (!$events) {
      $build['list']['empty'] = [
        '#markup' => '<div class="clota-agenda-empty"><h2>' . $this->t('No hem trobat cap activitat') . '</h2><p>' . $this->t('Prova de canviar o netejar els filtres.') . '</p></div>',
      ];
    }

    return $build;
  }

  /**
   * Builds the GET filter form.
   */
  private function filters(string $routeName, string $search, int $selectedType, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, array $typeLabels): array {
    $options = [
      'all' => [
        '#type' => 'html_tag',
        '#tag' => 'option',
        '#value' => $this->t('Tots els tipus'),
        '#attributes' => ['value' => ''],
      ],
    ];
    foreach ($typeLabels as $id => $label) {
      $options['type_' . $id] = [
        '#type' => 'html_tag',
        '#tag' => 'option',
        '#value' => $label,
        '#attributes' => ['value' => $id],
      ];
      if ($selectedType === $id) {
        $options['type_' . $id]['#attributes']['selected'] = 'selected';
      }
    }

    return [
      '#type' => 'html_tag',
      '#tag' => 'form',
      '#attributes' => [
        'class' => ['clota-agenda-filters'],
        'method' => 'get',
        'action' => Url::fromRoute($routeName)->toString(),
      ],
      'search_field' => [
        '#type' => 'container',
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'label',
          '#value' => $this->t('Cerca'),
          '#attributes' => ['for' => 'clota-agenda-search'],
        ],
        'input' => [
          '#type' => 'html_tag',
          '#tag' => 'input',
          '#attributes' => [
            'id' => 'clota-agenda-search',
            'type' => 'search',
            'name' => 'q',
            'value' => $search,
            'placeholder' => $this->t('Títol o paraula clau'),
          ],
        ],
      ],
      'type_field' => [
        '#type' => 'container',
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'label',
          '#value' => $this->t('Tipus d’activitat'),
          '#attributes' => ['for' => 'clota-agenda-type'],
        ],
        'select' => [
          '#type' => 'html_tag',
          '#tag' => 'select',
          '#attributes' => ['id' => 'clota-agenda-type', 'name' => 'type'],
        ] + $options,
      ],
      'from_field' => [
        '#type' => 'container',
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'label',
          '#value' => $this->t('Des de'),
          '#attributes' => ['for' => 'clota-agenda-from'],
        ],
        'input' => [
          '#type' => 'html_tag',
          '#tag' => 'input',
          '#attributes' => [
            'id' => 'clota-agenda-from',
            'type' => 'date',
            'name' => 'from',
            'value' => $from?->format('Y-m-d') ?? '',
          ],
        ],
      ],
      'to_field' => [
        '#type' => 'container',
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'label',
          '#value' => $this->t('Fins a'),
          '#attributes' => ['for' => 'clota-agenda-to'],
        ],
        'input' => [
          '#type' => 'html_tag',
          '#tag' => 'input',
          '#attributes' => [
            'id' => 'clota-agenda-to',
            'type' => 'date',
            'name' => 'to',
            'value' => $to?->format('Y-m-d') ?? '',
          ],
        ],
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['clota-agenda-filters__actions']],
        'submit' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => $this->t('Cercar'),
          '#attributes' => ['type' => 'submit'],
        ],
        'clear' => Link::fromTextAndUrl($this->t('Netejar'), Url::fromRoute($routeName))->toRenderable(),
      ],
    ];
  }

  /**
   * Returns active event type labels keyed by option value.
   */
  private function eventTypeLabels(): array {
    $result = civicrm_api3('OptionValue', 'get', [
      'option_group_id' => 'event_type',
      'is_active' => 1,
      'return' => ['value', 'label'],
      'options' => ['limit' => 0, 'sort' => 'weight ASC'],
    ]);
    $labels = [];
    foreach ($result['values'] as $option) {
      $labels[(int) $option['value']] = (string) $option['label'];
    }
    return $labels;
  }

  /**
   * Returns the API key for the event room custom field.
   */
  private function roomFieldKey(): string {
    $field = civicrm_api3('CustomField', 'getsingle', [
      'custom_group_id' => 'Agenda_La_Clota',
      'name' => 'Sala_Agenda',
      'return' => ['id'],
    ]);
    return 'custom_' . $field['id'];
  }

  /**
   * Parses an ISO date, ignoring malformed query parameters.
   */
  private function validDate(string $value): ?\DateTimeImmutable {
    if ($value === '') {
      return NULL;
    }
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Madrid'));
    return $date && $date->format('Y-m-d') === $value ? $date : NULL;
  }

}
