<?php

namespace Drupal\clota_interaction\Form;

use Drupal\Core\Access\AccessDeniedHttpException;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;

/**
 * Edits the directory data of the current user's linked CiviCRM contact.
 */
final class ClotaProfileForm extends FormBase {

  private const CUSTOM_FIELDS = [
    'Nom_Representant' => 'representative_first_name',
    'Cognoms_Representant' => 'representative_last_name',
    'Tots_Els_Membres' => 'all_members',
    'Ambit_Categoria' => 'category_ambit',
    'Descripcio_Directori' => 'description',
    'Serveis_Oferts' => 'services_offered',
    'Retorns_Socials' => 'social_returns',
    'Instagram' => 'url_instagram',
    'LinkedIn' => 'url_linkedin',
    'Facebook' => 'url_facebook',
    'TikTok' => 'url_tiktok',
    'YouTube' => 'url_youtube',
    'Behance' => 'url_behance',
  ];

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'clota_member_profile_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    \Drupal::service('civicrm')->initialize();
    $contactId = _clota_interaction_current_contact_id();
    if (!$contactId || !$this->isDirectoryMember($contactId)) {
      throw new AccessDeniedHttpException('No hi ha cap contacte de La Clota vinculat a aquest usuari.');
    }

    $customIds = $this->customFieldIds();
    $return = [
      'contact_type', 'organization_name', 'first_name', 'last_name', 'job_title',
    ];
    foreach ($customIds as $id) {
      $return[] = 'custom_' . $id;
    }
    $contact = civicrm_api3('Contact', 'getsingle', [
      'id' => $contactId,
      'return' => $return,
    ]);
    $address = $this->related('Address', $contactId);
    $email = $this->related('Email', $contactId);
    $phone = $this->related('Phone', $contactId);
    $website = $this->related('Website', $contactId);

    $form['contact_id'] = ['#type' => 'value', '#value' => $contactId];
    $form['contact_type'] = ['#type' => 'value', '#value' => $contact['contact_type']];
    $form['identity'] = [
      '#type' => 'details',
      '#title' => $this->t('Identitat'),
      '#open' => TRUE,
    ];
    if ($contact['contact_type'] === 'Organization') {
      $form['identity']['organization_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t("Nom de l'organització"),
        '#default_value' => $contact['organization_name'] ?? '',
        '#required' => TRUE,
        '#maxlength' => 128,
      ];
      $form['identity']['representative_first_name'] = $this->textField(
        'Nom de la persona representant',
        $contact['custom_' . ($customIds['Nom_Representant'] ?? 0)] ?? ''
      );
      $form['identity']['representative_last_name'] = $this->textField(
        'Cognoms de la persona representant',
        $contact['custom_' . ($customIds['Cognoms_Representant'] ?? 0)] ?? ''
      );
    }
    else {
      $form['identity']['first_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Nom'),
        '#default_value' => $contact['first_name'] ?? '',
        '#required' => TRUE,
        '#maxlength' => 64,
      ];
      $form['identity']['last_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Cognoms'),
        '#default_value' => $contact['last_name'] ?? '',
        '#required' => TRUE,
        '#maxlength' => 64,
      ];
    }
    $form['identity']['all_members'] = $this->textArea(
      'Membres',
      $contact['custom_' . ($customIds['Tots_Els_Membres'] ?? 0)] ?? '',
      2
    );
    $form['identity']['job_title'] = $this->textField('Activitat o càrrec', $contact['job_title'] ?? '');

    $form['directory'] = [
      '#type' => 'details',
      '#title' => $this->t('Informació del directori'),
      '#open' => TRUE,
    ];
    $directoryFields = [
      'category_ambit' => ['Àmbit o categoria', 'Ambit_Categoria', 2],
      'description' => ['Descripció', 'Descripcio_Directori', 5],
      'services_offered' => ['Serveis oferts', 'Serveis_Oferts', 5],
      'social_returns' => ['Retorns socials', 'Retorns_Socials', 5],
    ];
    foreach ($directoryFields as $key => [$title, $customName, $rows]) {
      $form['directory'][$key] = $this->textArea(
        $title,
        $contact['custom_' . ($customIds[$customName] ?? 0)] ?? '',
        $rows
      );
    }

    $form['contact'] = [
      '#type' => 'details',
      '#title' => $this->t('Contacte i enllaços'),
      '#open' => TRUE,
    ];
    $form['contact']['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Correu electrònic'),
      '#default_value' => $email['email'] ?? '',
    ];
    $form['contact']['phone'] = $this->textField('Telèfon', $phone['phone'] ?? '');
    $form['contact']['website'] = [
      '#type' => 'url',
      '#title' => $this->t('Lloc web'),
      '#default_value' => $website['url'] ?? '',
    ];
    foreach (['Instagram', 'LinkedIn', 'Facebook', 'TikTok', 'YouTube', 'Behance'] as $customName) {
      $key = self::CUSTOM_FIELDS[$customName];
      $form['contact'][$key] = $this->textArea(
        $customName,
        $contact['custom_' . ($customIds[$customName] ?? 0)] ?? '',
        2
      );
      $form['contact'][$key]['#description'] = $this->t('Pots indicar més d’un enllaç separant-los amb punt i coma.');
    }

    $form['address'] = [
      '#type' => 'details',
      '#title' => $this->t('Adreça'),
    ];
    foreach ([
      'street_address' => ['Adreça', 255],
      'supplemental_address_1' => ['Informació addicional', 255],
      'city' => ['Ciutat', 64],
      'postal_code' => ['Codi postal', 12],
    ] as $key => [$title, $maxlength]) {
      $form['address'][$key] = $this->textField($title, $address[$key] ?? '', $maxlength);
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Desar el perfil'),
      '#button_type' => 'primary',
    ];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    \Drupal::service('civicrm')->initialize();
    $contactId = (int) $form_state->getValue('contact_id');
    if ($contactId !== _clota_interaction_current_contact_id() || !$this->isDirectoryMember($contactId)) {
      throw new AccessDeniedHttpException();
    }

    $customIds = $this->customFieldIds();
    $params = [
      'id' => $contactId,
      'job_title' => trim((string) $form_state->getValue('job_title')),
    ];
    if ($form_state->getValue('contact_type') === 'Organization') {
      $params['organization_name'] = trim((string) $form_state->getValue('organization_name'));
    }
    else {
      $params['first_name'] = trim((string) $form_state->getValue('first_name'));
      $params['last_name'] = trim((string) $form_state->getValue('last_name'));
    }
    foreach (self::CUSTOM_FIELDS as $customName => $formKey) {
      if (isset($customIds[$customName])) {
        $params['custom_' . $customIds[$customName]] = trim((string) $form_state->getValue($formKey));
      }
    }
    civicrm_api3('Contact', 'create', $params);

    $this->saveRelated('Address', $contactId, [
      'location_type_id' => 1,
      'is_primary' => 1,
      'street_address' => trim((string) $form_state->getValue('street_address')),
      'supplemental_address_1' => trim((string) $form_state->getValue('supplemental_address_1')),
      'city' => trim((string) $form_state->getValue('city')),
      'postal_code' => trim((string) $form_state->getValue('postal_code')),
    ]);
    $email = trim((string) $form_state->getValue('email'));
    $this->saveRelated('Email', $contactId, [
      'location_type_id' => 1,
      'is_primary' => 1,
      'email' => $email,
    ]);
    $this->saveRelated('Phone', $contactId, [
      'location_type_id' => 1,
      'is_primary' => 1,
      'phone_type_id' => 1,
      'phone' => trim((string) $form_state->getValue('phone')),
    ]);
    $this->saveRelated('Website', $contactId, [
      'website_type_id' => 2,
      'url' => trim((string) $form_state->getValue('website')),
    ]);

    if ($email !== '') {
      $account = User::load($this->currentUser()->id());
      if ($account) {
        $account->setEmail($email)->save();
      }
    }
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['clota_directory']);
    $this->messenger()->addStatus($this->t('El perfil s’ha actualitzat correctament.'));
  }

  private function textField(string $title, string $value, int $maxlength = 255): array {
    return [
      '#type' => 'textfield',
      '#title' => $this->t($title),
      '#default_value' => $value,
      '#maxlength' => $maxlength,
    ];
  }

  private function textArea(string $title, string $value, int $rows): array {
    return [
      '#type' => 'textarea',
      '#title' => $this->t($title),
      '#default_value' => $value,
      '#rows' => $rows,
    ];
  }

  private function customFieldIds(): array {
    $result = civicrm_api3('CustomField', 'get', [
      'custom_group_id' => 'Directori_La_Clota',
      'name' => ['IN' => array_keys(self::CUSTOM_FIELDS)],
      'return' => ['id', 'name'],
      'options' => ['limit' => 0],
    ]);
    $ids = [];
    foreach ($result['values'] as $field) {
      $ids[$field['name']] = (int) $field['id'];
    }
    return $ids;
  }

  private function isDirectoryMember(int $contactId): bool {
    return (int) civicrm_api3('GroupContact', 'getcount', [
      'group_id' => 'usuarios_clota',
      'contact_id' => $contactId,
      'status' => 'Added',
    ]) > 0;
  }

  private function related(string $entity, int $contactId): array {
    $result = civicrm_api3($entity, 'get', [
      'contact_id' => $contactId,
      'sequential' => 1,
      'options' => ['limit' => 1, 'sort' => 'id ASC'],
    ]);
    return $result['values'][0] ?? [];
  }

  private function saveRelated(string $entity, int $contactId, array $values): void {
    $existing = $this->related($entity, $contactId);
    $contentKeys = array_diff(array_keys($values), [
      'location_type_id', 'is_primary', 'phone_type_id', 'website_type_id',
    ]);
    $hasContent = FALSE;
    foreach ($contentKeys as $key) {
      $hasContent = $hasContent || trim((string) ($values[$key] ?? '')) !== '';
    }
    if (!$hasContent) {
      if (!empty($existing['id'])) {
        civicrm_api3($entity, 'delete', ['id' => $existing['id']]);
      }
      return;
    }
    civicrm_api3($entity, 'create', ['contact_id' => $contactId] +
      (!empty($existing['id']) ? ['id' => $existing['id']] : []) + $values);
  }

}
