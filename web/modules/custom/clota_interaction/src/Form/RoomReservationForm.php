<?php

namespace Drupal\clota_interaction\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides the Clota room reservation form.
 */
final class RoomReservationForm extends FormBase {

  private const STANDARD_DURATIONS = [15, 30, 45, 60];
  private const EXTERNAL_DURATIONS = [15, 30, 45, 60, 120, 180, 240, 300];

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
      '#markup' => '<p>' . $this->t('Selecciona una sala, una data, una hora d’inici i una durada. No es permeten reserves solapades per a la mateixa sala.') . '</p>',
    ];
    $form['room'] = [
      '#type' => 'select',
      '#title' => $this->t('Sala'),
      '#options' => \_clota_interaction_room_labels(),
      '#default_value' => 'clota',
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::updateRoomOptions',
        'wrapper' => 'clota-room-options',
      ],
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
    $room = $form_state->getValue('room') ?: 'clota';
    $durations = $room === 'externa' ? self::EXTERNAL_DURATIONS : self::STANDARD_DURATIONS;
    $durationOptions = [];
    foreach ($durations as $minutes) {
      $durationOptions[$minutes] = $minutes < 60
        ? $this->t('@minutes minuts', ['@minutes' => $minutes])
        : $this->formatPlural(intdiv($minutes, 60), '1 hora', '@count hores');
    }
    $form['room_options'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'clota-room-options'],
    ];
    $form['room_options']['duration'] = [
      '#type' => 'select',
      '#title' => $this->t('Durada'),
      '#options' => $durationOptions,
      '#default_value' => 60,
      '#required' => TRUE,
    ];
    if ($room === 'externa') {
      $form['room_options']['attendees'] = [
        '#type' => 'number',
        '#title' => $this->t('Quanta gent vindrà?'),
        '#min' => 1,
        '#step' => 1,
        '#required' => TRUE,
      ];
      $form['room_options']['needs_tv'] = [
        '#type' => 'radios',
        '#title' => $this->t('Necessites televisió?'),
        '#options' => ['no' => $this->t('No'), 'yes' => $this->t('Sí')],
        '#required' => TRUE,
      ];
      $form['room_options']['approval_notice'] = [
        '#markup' => '<p>' . $this->t("La reserva de la sala externa quedarà pendent de validació per l'Administració de la Clota.") . '</p>',
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reserva la sala'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * Rebuilds duration and extra fields when the selected room changes.
   */
  public function updateRoomOptions(array &$form, FormStateInterface $form_state): array {
    return $form['room_options'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $room = (string) $form_state->getValue('room');
    if (!isset(\_clota_interaction_room_labels()[$room])) {
      $form_state->setErrorByName('room', $this->t('La sala no és vàlida.'));
      return;
    }
    $duration = (int) $form_state->getValue('duration');
    $allowedDurations = $room === 'externa' ? self::EXTERNAL_DURATIONS : self::STANDARD_DURATIONS;
    if (!in_array($duration, $allowedDurations, TRUE)) {
      $form_state->setErrorByName('duration', $this->t('La durada no és vàlida.'));
      return;
    }
    if ($room === 'externa' && (int) $form_state->getValue('attendees') < 1) {
      $form_state->setErrorByName('attendees', $this->t('Indica quanta gent vindrà.'));
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
    if (\Drupal::service('clota_interaction.room_reservations')->hasOverlap($start, $end, $room)) {
      $form_state->setErrorByName('start_time', $this->t('Aquest interval ja està reservat. Tria una altra hora.'));
      return;
    }

    $form_state->set('reservation_start', $start);
    $form_state->set('reservation_end', $end);
    $form_state->set('reservation_room', $room);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $start = (int) $form_state->get('reservation_start');
    $end = (int) $form_state->get('reservation_end');
    $room = (string) $form_state->get('reservation_room');
    $attendees = $room === 'externa' ? (int) $form_state->getValue('attendees') : NULL;
    $needsTv = $room === 'externa' ? $form_state->getValue('needs_tv') === 'yes' : NULL;
    $reservationStatus = $room === 'externa' ? 'pending_validation' : 'confirmed';
    $lockName = 'clota_room_reservation_write';
    $activityId = 0;

    $lock = \Drupal::service('lock');
    if (!$lock->acquire($lockName, 10.0)) {
      $this->messenger()->addError($this->t('Una altra reserva s’està processant. Torna-ho a provar.'));
      return;
    }

    try {
      if (\Drupal::service('clota_interaction.room_reservations')->hasOverlap($start, $end, $room)) {
        $this->messenger()->addError($this->t('Aquest interval acaba de ser reservat. Tria una altra hora.'));
        return;
      }

      $activityId = \_clota_interaction_create_reservation_activity(
        (int) \Drupal::currentUser()->id(),
        $start,
        $end,
        $room,
        $attendees,
        $needsTv,
        $reservationStatus,
      );
      \Drupal::database()->insert('clota_room_reservation')
        ->fields([
          'uid' => (int) \Drupal::currentUser()->id(),
          'start_at' => $start,
          'end_at' => $end,
          'created' => \Drupal::time()->getRequestTime(),
          'civicrm_activity_id' => $activityId,
          'room' => $room,
          'status' => $reservationStatus,
          'attendees' => $attendees,
          'needs_tv' => $needsTv === NULL ? NULL : (int) $needsTv,
        ])
        ->execute();
    }
    catch (\Throwable $exception) {
      if ($activityId) {
        try {
          civicrm_api3('Activity', 'delete', ['id' => $activityId]);
        }
        catch (\Throwable $cleanupException) {
          $this->getLogger('clota_interaction')->warning('Could not remove orphan CiviCRM activity @id: @message', [
            '@id' => $activityId,
            '@message' => $cleanupException->getMessage(),
          ]);
        }
      }
      $this->getLogger('clota_interaction')->error('Room reservation failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      $this->messenger()->addError($this->t("No s'ha pogut registrar la reserva a CiviCRM. Torna-ho a provar."));
      return;
    }
    finally {
      $lock->release($lockName);
    }

    $dateFormatter = \Drupal::service('date.formatter');
    $replacements = [
      '@room' => \_clota_interaction_room_labels()[$room],
      '@date' => $dateFormatter->format($start, 'custom', 'd/m/Y', 'Europe/Madrid'),
      '@start' => $dateFormatter->format($start, 'custom', 'H:i', 'Europe/Madrid'),
      '@end' => $dateFormatter->format($end, 'custom', 'H:i', 'Europe/Madrid'),
    ];
    $message = $reservationStatus === 'pending_validation'
      ? $this->t('Reserva enviada i pendent de validació administrativa: @room, @date de @start a @end.', $replacements)
      : $this->t('Sala reservada: @room, @date de @start a @end.', $replacements);
    $this->messenger()->addStatus($message);
    $form_state->setRedirect('clota_interaction.room_reservation');
  }

}
