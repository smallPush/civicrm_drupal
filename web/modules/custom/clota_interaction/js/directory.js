(function (Drupal, once) {
  'use strict';

  const normalize = (value) => value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLocaleLowerCase()
    .trim();

  Drupal.behaviors.clotaDirectoryFilters = {
    attach(context) {
      once('clota-directory-filters', '[data-clota-directory-view]', context).forEach((view) => {
        const filters = view.querySelector('[data-clota-directory-filters]');
        const search = view.querySelector('[data-clota-directory-search]');
        const tag = view.querySelector('[data-clota-directory-tag]');
        const clear = view.querySelector('[data-clota-directory-clear]');
        const status = view.querySelector('[data-clota-directory-status]');
        const empty = view.querySelector('[data-clota-directory-empty]');
        const cards = Array.from(view.querySelectorAll('[data-clota-directory-card]'));

        if (!filters || !search || !tag || !clear || !status || !empty) {
          return;
        }

        const update = () => {
          const query = normalize(search.value);
          const selectedTag = normalize(tag.value);
          let matches = 0;

          cards.forEach((card) => {
            const nameMatches = normalize(card.dataset.clotaName || '').includes(query);
            const tags = JSON.parse(card.dataset.clotaTags || '[]').map(normalize);
            const tagMatches = !selectedTag || tags.includes(selectedTag);
            const visible = nameMatches && tagMatches;

            card.hidden = !visible;
            matches += visible ? 1 : 0;
          });

          status.textContent = Drupal.formatPlural(matches, '1 resultat', '@count resultats');
          clear.hidden = !query && !selectedTag;
          empty.hidden = matches !== 0;
        };

        search.addEventListener('input', update);
        tag.addEventListener('change', update);
        clear.addEventListener('click', () => {
          search.value = '';
          tag.value = '';
          update();
          search.focus();
        });

        filters.hidden = false;
        update();
      });
    },
  };
})(Drupal, once);
