<?php

namespace Drupal\clota_interaction\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Session\AccountInterface;

/**
 * Displays the current Clota user's CiviCRM activity history.
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
    $types = civicrm_api3('OptionValue', 'get', [
      'option_group_id' => 'activity_type',
      'name' => ['IN' => ['Interaccion_Clota', 'Reserva_Sala']],
      'return' => ['value', 'name'],
      'options' => ['limit' => 0],
    ]);
    $typeNames = [];
    foreach ($types['values'] as $type) {
      $typeNames[(int) $type['value']] = $type['name'];
    }
    if (!$typeNames) {
      return $this->emptyState();
    }

    $result = civicrm_api3('Activity', 'get', [
      'contact_id' => $contactId,
      'activity_type_id' => ['IN' => array_values($typeNames)],
      'is_deleted' => 0,
      'sequential' => 1,
      'return' => [
        'id',
        'activity_type_id',
        'subject',
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
      $isReservation = ($typeNames[(int) $activity['activity_type_id']] ?? '') === 'Reserva_Sala';
      if ($isReservation) {
        $people = [];
      }
      elseif ((string) $activity['source_contact_id'] === $contactId) {
        $people = array_values($activity['target_contact_name'] ?? []);
      }
      else {
        $people = [$activity['source_contact_name'] ?? ''];
      }

      $rows[] = [
        'type' => $isReservation ? $this->t('Reserva de sala') : $this->t('Interacció'),
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
        $this->t('Activitat'),
        $this->t('Date'),
        $this->t('Persona'),
        $this->t('Notes'),
        $this->t('Estat'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Encara no tens activitat registrada.'),
      '#cache' => [
        'contexts' => ['user'],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Returns the empty state for users without activity.
   */
  private function emptyState(): array {
    return [
      '#markup' => '<p>' . $this->t('Encara no tens activitat registrada.') . '</p>',
      '#cache' => [
        'contexts' => ['user'],
        'max-age' => 0,
      ],
    ];
  }

}
