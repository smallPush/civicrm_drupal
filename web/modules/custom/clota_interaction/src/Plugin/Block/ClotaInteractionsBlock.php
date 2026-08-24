<?php

namespace Drupal\clota_interaction\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Session\AccountInterface;

/**
 * Displays the current Clota user's CiviCRM interactions.
 *
 * @Block(
 *   id = "clota_interactions",
 *   admin_label = @Translation("Clota user interactions"),
 *   category = @Translation("Clota")
 * )
 */
final class ClotaInteractionsBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIf(
      $account->isAuthenticated() && in_array('usuario_clota', $account->getRoles(), TRUE)
    )->addCacheContexts(['user.roles']);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    \Drupal::service('civicrm')->initialize();
    $uid = (int) \Drupal::currentUser()->id();
    $match = civicrm_api3('UFMatch', 'get', [
      'uf_id' => $uid,
      'return' => ['contact_id'],
      'options' => ['limit' => 1],
      'check_permissions' => FALSE,
    ]);
    if (empty($match['values'])) {
      return $this->emptyState();
    }

    $contactId = (string) reset($match['values'])['contact_id'];
    $result = civicrm_api3('Activity', 'get', [
      'contact_id' => $contactId,
      'activity_type_id' => 'Interaccion_Clota',
      'is_deleted' => 0,
      'sequential' => 1,
      'return' => [
        'id',
        'activity_date_time',
        'details',
        'status_id',
        'source_contact_id',
        'target_contact_id',
      ],
      'options' => [
        'sort' => 'activity_date_time DESC',
        'limit' => 50,
      ],
      'check_permissions' => FALSE,
    ]);

    if (empty($result['values'])) {
      return $this->emptyState();
    }

    $rows = [];
    foreach ($result['values'] as $activity) {
      if ((string) $activity['source_contact_id'] === $contactId) {
        $people = array_values($activity['target_contact_name'] ?? []);
      }
      else {
        $people = [$activity['source_contact_name'] ?? ''];
      }

      $rows[] = [
        'date' => \Drupal::service('date.formatter')->format(
          strtotime($activity['activity_date_time']),
          'custom',
          'd/m/Y H:i'
        ),
        'contact' => implode(', ', array_filter($people)),
        'details' => trim(strip_tags(html_entity_decode($activity['details'] ?? ''))),
        'status' => (int) ($activity['status_id'] ?? 0) === 2
          ? 'Completada'
          : ($activity['status'] ?? ''),
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Date'),
        $this->t('Persona'),
        $this->t('Notes'),
        $this->t('Estat'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Encara no tens interaccions registrades.'),
      '#cache' => [
        'contexts' => ['user'],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Returns the empty state for users without interactions.
   */
  private function emptyState(): array {
    return [
      '#markup' => '<p>' . $this->t('Encara no tens interaccions registrades.') . '</p>',
      '#cache' => [
        'contexts' => ['user'],
        'max-age' => 0,
      ],
    ];
  }

}
