<?php

namespace Drupal\clota_interaction\Form;

use Drupal\clota_interaction\RoomReservationSchedule;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Clota room reservation form.
 */
final class RoomReservationForm extends FormBase {

  private const DURATIONS = [15, 30, 45, 60];

  public function __construct(
    private readonly Connection $database,
    private readonly LockBackendInterface $lock,
    private readonly AccountProxyInterface $currentUser,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly RoomReservationSchedule $schedule,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('lock'),
      $container->get('current_user'),
      $container->get('date.formatter'),
      $container->get('clota_interaction.room_reservations'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'clota_room_reservation_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $timezone = new \DateTimeZone('Europe/Madrid');
    $today = (new \DateTimeImmutable('now', $timezone))->format('Y-m-d');

    $times = [];
    for ($minutes = 0; $minutes < 24 * 60; $minutes += 15) {
      $time = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
      $times[$time] = $time;
    }

    $form['instructions'] = [
      '#markup' => '<p>' . $this->t('Consulta les hores ocupades i selecciona una data, una hora d’inici i una durada. No es permeten reserves solapades.') . '</p>',
    ];
    $form['date'] = [
      '#type' => 'date',
      '#title' => $this->t('Data'),
      '#default_value' => $today,
      '#attributes' => ['min' => $today],
      '#required' => TRUE,
    ];
    $form['start_time'] = [
      '#type' => 'select',
      '#title' => $this->t('Hora d’inici'),
      '#options' => $times,
      '#default_value' => '09:00',
      '#required' => TRUE,
    ];
    $form['duration'] = [
      '#type' => 'select',
      '#title' => $this->t('Durada'),
      '#options' => [
        15 => $this->t('15 minuts'),
        30 => $this->t('30 minuts'),
        45 => $this->t('45 minuts'),
        60 => $this->t('1 hora'),
      ],
      '#default_value' => 60,
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reserva la sala'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $duration = (int) $form_state->getValue('duration');
    if (!in_array($duration, self::DURATIONS, TRUE)) {
      $form_state->setErrorByName('duration', $this->t('La durada no és vàlida.'));
      return;
    }

    $dateTime = \DateTimeImmutable::createFromFormat(
      '!Y-m-d H:i',
      $form_state->getValue('date') . ' ' . $form_state->getValue('start_time'),
      new \DateTimeZone('Europe/Madrid'),
    );
    $errors = \DateTimeImmutable::getLastErrors();
    if (!$dateTime || ($errors !== FALSE && ($errors['warning_count'] || $errors['error_count']))) {
      $form_state->setErrorByName('date', $this->t('La data o l’hora no és vàlida.'));
      return;
    }

    $start = $dateTime->getTimestamp();
    $end = $dateTime->modify("+$duration minutes")->getTimestamp();
    if ($start <= \Drupal::time()->getRequestTime()) {
      $form_state->setErrorByName('date', $this->t('La reserva ha de començar en el futur.'));
      return;
    }
    if ($this->schedule->hasOverlap($start, $end)) {
      $form_state->setErrorByName('start_time', $this->t('Aquest interval ja està reservat. Tria una altra hora.'));
      return;
    }

    $form_state->set('reservation_start', $start);
    $form_state->set('reservation_end', $end);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $start = (int) $form_state->get('reservation_start');
    $end = (int) $form_state->get('reservation_end');
    $lockName = 'clota_room_reservation_write';

    if (!$this->lock->acquire($lockName, 10.0)) {
      $this->messenger()->addError($this->t('Una altra reserva s’està processant. Torna-ho a provar.'));
      return;
    }

    try {
      if ($this->schedule->hasOverlap($start, $end)) {
        $this->messenger()->addError($this->t('Aquest interval acaba de ser reservat. Tria una altra hora.'));
        return;
      }

      $this->database->insert('clota_room_reservation')
        ->fields([
          'uid' => (int) $this->currentUser->id(),
          'start_at' => $start,
          'end_at' => $end,
          'created' => \Drupal::time()->getRequestTime(),
        ])
        ->execute();
    }
    finally {
      $this->lock->release($lockName);
    }

    $this->messenger()->addStatus($this->t('Sala reservada el @date de @start a @end.', [
      '@date' => $this->dateFormatter->format($start, 'custom', 'd/m/Y'),
      '@start' => $this->dateFormatter->format($start, 'custom', 'H:i'),
      '@end' => $this->dateFormatter->format($end, 'custom', 'H:i'),
    ]));
    $form_state->setRedirect('clota_interaction.room_reservation');
  }

}
