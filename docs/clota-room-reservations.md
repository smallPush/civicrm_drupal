# Reserves de sales de La Clota

El mòdul `clota_interaction` permet reservar tres sales des de
`/reserva-sala`:

- `clota`: Sala Clota, confirmació immediata.
- `groga`: Sala groga, confirmació immediata.
- `externa`: Sala externa, pendent de validació administrativa.

Les reserves de la sala externa també demanen el nombre d'assistents i si es
necessita televisió. La Sala Clota i la Sala groga admeten reserves de 15, 30,
45 o 60 minuts. La Sala externa admet també 2, 3, 4 o 5 hores.

## Desplegament

1. Crear una còpia de seguretat de la base de dades i del volum de fitxers.
2. Desplegar el codi del mòdul des de la branca de producció.
3. Executar `./vendor/bin/drush updatedb -y` dins del contenidor web.
4. Executar `./vendor/bin/drush cr`.
5. Verificar que l'actualització `clota_interaction_update_11012` ha creat o
   conservat els camps `civicrm_activity_id`, `room`, `status`, `attendees` i
   `needs_tv` de la taula `clota_room_reservation`.
6. Verificar a CiviCRM el tipus d'activitat `Reserva_Sala`, l'estat
   `Pending_Clota_Approval` i el grup de camps `Clota_Room_Reservation`.
7. Provar una reserva simultània en dues sales diferents i confirmar que no es
   bloquegen entre elles.
8. Provar una reserva de la Sala externa i confirmar que queda amb l'estat
   `pending_validation`, tant a Drupal com a CiviCRM.
9. Comprovar al calendari els colors de Sala Clota, Sala groga i Sala externa,
   i el contorn discontinu de les reserves pendents.

L'actualització `11012` és idempotent: conserva les reserves existents,
completa les columnes i índexs que faltin i vincula a CiviCRM qualsevol reserva
antiga que encara no tingui activitat associada.
