<?php

namespace Drupal\clota_interaction\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;

/**
 * Displays the public directory of Clota members.
 */
final class ClotaDirectoryController extends ControllerBase {

  private const CUSTOM_FIELDS = [
    'Tots_Els_Membres',
    'Ambit_Categoria',
    'Descripcio_Directori',
    'Serveis_Oferts',
    'Instagram',
    'LinkedIn',
    'Facebook',
    'TikTok',
    'YouTube',
    'Behance',
    'Etiquetes_Directori',
  ];

  /**
   * Builds the directory cards.
   */
  public function build(): array {
    \Drupal::service('civicrm')->initialize();
    $group = civicrm_api3('Group', 'getsingle', ['name' => 'usuarios_clota']);
    $memberships = civicrm_api3('GroupContact', 'get', [
      'group_id' => $group['id'],
      'status' => 'Added',
      'return' => ['contact_id'],
      'options' => ['limit' => 0],
    ]);
    $customIds = $this->customFieldIds();
    $return = ['external_identifier', 'display_name', 'organization_name', 'first_name', 'last_name', 'job_title', 'image_URL'];
    foreach ($customIds as $id) {
      $return[] = 'custom_' . $id;
    }

    $contacts = [];
    foreach ($memberships['values'] as $membership) {
      $contact = civicrm_api3('Contact', 'getsingle', [
        'id' => $membership['contact_id'],
        'is_deleted' => 0,
        'return' => $return,
      ]);
      if (str_starts_with((string) ($contact['external_identifier'] ?? ''), 'CLOTA-')) {
        $contacts[] = $contact;
      }
    }
    usort($contacts, static fn(array $a, array $b): int =>
      strcasecmp((string) $a['display_name'], (string) $b['display_name']));

    $availableTags = [];
    foreach ($contacts as $contact) {
      foreach ($this->tags($contact, $customIds) as $tag) {
        $availableTags[mb_strtolower($tag)] = $tag;
      }
    }
    uasort($availableTags, 'strnatcasecmp');

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['clota-directory-view'], 'data-clota-directory-view' => ''],
      '#attached' => ['library' => ['clota_interaction/directory']],
      '#cache' => ['tags' => ['clota_directory'], 'max-age' => 300],
      'intro' => [
        '#markup' => '<p class="clota-directory__intro">' . $this->t(
          'Coneix els projectes, professionals i serveis que formen la comunitat de La Clota.'
          ) . '</p>',
      ],
      'filters' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['clota-directory-filters'], 'data-clota-directory-filters' => '', 'hidden' => 'hidden'],
        'search_group' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['clota-directory-filters__field']],
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'label',
            '#value' => $this->t('Cerca per nom'),
            '#attributes' => ['for' => 'clota-directory-search'],
          ],
          'input' => [
            '#type' => 'html_tag',
            '#tag' => 'input',
            '#attributes' => [
              'id' => 'clota-directory-search',
              'type' => 'search',
              'placeholder' => $this->t('Nom del projecte o professional'),
              'autocomplete' => 'off',
              'data-clota-directory-search' => '',
            ],
          ],
        ],
        'tag_group' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['clota-directory-filters__field']],
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'label',
            '#value' => $this->t('Filtra per etiqueta'),
            '#attributes' => ['for' => 'clota-directory-tag'],
          ],
          'select' => [
            '#type' => 'html_tag',
            '#tag' => 'select',
            '#attributes' => ['id' => 'clota-directory-tag', 'data-clota-directory-tag' => ''],
            'all' => [
              '#type' => 'html_tag',
              '#tag' => 'option',
              '#value' => $this->t('Totes les etiquetes'),
              '#attributes' => ['value' => ''],
            ],
          ],
        ],
        'clear' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => $this->t('Neteja els filtres'),
          '#attributes' => [
            'type' => 'button',
            'class' => ['clota-directory-filters__clear'],
            'data-clota-directory-clear' => '',
            'hidden' => 'hidden',
          ],
        ],
        'status' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('@count resultats', ['@count' => count($contacts)]),
          '#attributes' => [
            'class' => ['clota-directory-filters__status'],
            'data-clota-directory-status' => '',
            'aria-live' => 'polite',
          ],
        ],
      ],
      'grid' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['clota-directory']],
      ],
    ];

    foreach ($availableTags as $key => $tag) {
      $build['filters']['tag_group']['select']['tag_' . md5($key)] = [
        '#type' => 'html_tag',
        '#tag' => 'option',
        '#value' => $tag,
        '#attributes' => ['value' => $key],
      ];
    }

    foreach ($contacts as $contact) {
      $id = (int) $contact['id'];
      $website = $this->relatedUrl('Website', $id, 'url');
      $name = (string) $contact['display_name'];
      $title = UrlHelper::isValid($website, TRUE)
        ? Link::fromTextAndUrl($name, Url::fromUri($website, ['attributes' => ['target' => '_blank', 'rel' => 'noopener noreferrer']]))->toRenderable()
        : ['#plain_text' => $name];
      $card = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['clota-directory-card'],
          'data-clota-directory-card' => '',
          'data-clota-name' => $name,
          'data-clota-tags' => Json::encode(array_map('mb_strtolower', $this->tags($contact, $customIds))),
        ],
        'title' => ['#type' => 'html_tag', '#tag' => 'h2', 'content' => $title],
      ];
      $imageUrl = $this->imageUrl($contact);
      if ($imageUrl !== '') {
        $card['image'] = [
          '#type' => 'html_tag',
          '#tag' => 'img',
          '#weight' => -10,
          '#attributes' => [
            'class' => ['clota-directory-card__image'],
            'src' => $imageUrl,
            'alt' => '',
            'loading' => 'lazy',
            'decoding' => 'async',
          ],
        ];
      }
      $this->addText($card, 'role', $contact['job_title'] ?? '', 'clota-directory-card__role');
      $this->addText($card, 'category', $this->customValue($contact, $customIds, 'Ambit_Categoria'), 'clota-directory-card__category');
      $this->addText($card, 'members', $this->customValue($contact, $customIds, 'Tots_Els_Membres'), 'clota-directory-card__members');
      $this->addText($card, 'description', $this->customValue($contact, $customIds, 'Descripcio_Directori'), 'clota-directory-card__description');
      $this->addSection($card, 'services', $this->t('Serveis'), $this->customValue($contact, $customIds, 'Serveis_Oferts'));

      $socialLinks = [];
      foreach (['Instagram', 'LinkedIn', 'Facebook', 'TikTok', 'YouTube', 'Behance'] as $network) {
        foreach (preg_split('/\s*;\s*/', $this->customValue($contact, $customIds, $network), -1, PREG_SPLIT_NO_EMPTY) as $url) {
          if (UrlHelper::isValid($url, TRUE)) {
            $socialLinks[] = Link::fromTextAndUrl($network, Url::fromUri($url, [
              'attributes' => ['target' => '_blank', 'rel' => 'noopener noreferrer'],
            ]))->toRenderable();
          }
        }
      }
      if ($socialLinks) {
        $card['social'] = [
          '#theme' => 'item_list',
          '#items' => $socialLinks,
          '#attributes' => ['class' => ['clota-directory-card__social']],
        ];
      }
      $build['grid']['contact_' . $id] = $card;
    }

    $build['no_results'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['clota-directory-empty'], 'data-clota-directory-empty' => '', 'hidden' => 'hidden'],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('No hem trobat cap resultat'),
      ],
      'text' => [
        '#markup' => '<p>' . $this->t('Prova un altre nom o selecciona una etiqueta diferent.') . '</p>',
      ],
    ];

    if (!$contacts) {
      $build['grid']['empty'] = ['#markup' => '<p>' . $this->t('El directori encara no té membres.') . '</p>'];
    }
    return $build;
  }

  private function customFieldIds(): array {
    $result = civicrm_api3('CustomField', 'get', [
      'custom_group_id' => 'Directori_La_Clota',
      'name' => ['IN' => self::CUSTOM_FIELDS],
      'return' => ['id', 'name'],
      'options' => ['limit' => 0],
    ]);
    $ids = [];
    foreach ($result['values'] as $field) {
      $ids[$field['name']] = (int) $field['id'];
    }
    return $ids;
  }

  private function customValue(array $contact, array $customIds, string $name): string {
    return trim((string) ($contact['custom_' . ($customIds[$name] ?? 0)] ?? ''));
  }

  private function tags(array $contact, array $customIds): array {
    return preg_split(
      '/\s*;\s*/',
      $this->customValue($contact, $customIds, 'Etiquetes_Directori'),
      -1,
      PREG_SPLIT_NO_EMPTY,
    ) ?: [];
  }

  private function imageUrl(array $contact): string {
    $url = trim((string) ($contact['image_URL'] ?? ''));
    return str_starts_with($url, '/sites/default/files/civicrm/persist/contribute/')
      ? $url
      : '';
  }

  private function addText(array &$card, string $key, string $value, string $class): void {
    if (trim($value) !== '') {
      $card[$key] = ['#markup' => '<p class="' . $class . '">' . nl2br(Html::escape(trim($value))) . '</p>'];
    }
  }

  private function addSection(array &$card, string $key, string $title, string $value): void {
    if (trim($value) !== '') {
      $card[$key] = [
        '#type' => 'details',
        '#title' => $title,
        'content' => ['#markup' => Markup::create('<div>' . nl2br(Html::escape(trim($value))) . '</div>')],
      ];
    }
  }

  private function relatedUrl(string $entity, int $contactId, string $field): string {
    $result = civicrm_api3($entity, 'get', [
      'contact_id' => $contactId,
      'return' => ['id', $field],
      'sequential' => 1,
      'options' => ['limit' => 1],
    ]);
    return trim((string) ($result['values'][0][$field] ?? ''));
  }

}
