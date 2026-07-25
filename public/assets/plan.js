(() => {
  'use strict';

  const warning = 'Des modifications de ce panneau ne sont pas encore enregistrées.';
  const dirtyForms = new Set();

  const setDirty = (form) => {
    if (!form) return;
    dirtyForms.add(form);
    form.dataset.dirty = 'true';
    document.querySelectorAll(`[data-panel-submit][form="${form.id}"]`)
      .forEach((button) => { button.disabled = false; });
    form.querySelectorAll('[data-panel-submit]')
      .forEach((button) => { button.disabled = false; });
  };

  const clearDirty = (form) => {
    dirtyForms.delete(form);
    form.dataset.dirty = 'false';
  };

  document.querySelectorAll('[data-dirty-panel]').forEach((form) => {
    const mark = () => setDirty(form);
    form.addEventListener('input', mark);
    form.addEventListener('change', mark);

    document.querySelectorAll(`[form="${form.id}"]`).forEach((control) => {
      control.addEventListener('input', mark);
      control.addEventListener('change', mark);
    });

    form.addEventListener('submit', () => clearDirty(form));
  });

  document.querySelectorAll('[data-sortable]').forEach((body) => {
    let dragged = null;
    const formId = body.dataset.orderForm;
    const form = formId ? document.getElementById(formId) : null;

    const recordOrder = () => {
      if (!form) return;
      const input = form.querySelector('[name="ordre_liste"]');
      if (!input) return;
      input.value = Array.from(body.querySelectorAll('[data-item-id]'))
        .map((row) => row.dataset.itemId)
        .join(',');
      setDirty(form);
    };

    body.addEventListener('dragstart', (event) => {
      const row = event.target.closest('[data-item-id]');
      if (!row || !form) return;
      dragged = row;
      row.classList.add('dragging');
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', row.dataset.itemId);
    });

    body.addEventListener('dragover', (event) => {
      if (!dragged) return;
      event.preventDefault();
      const target = event.target.closest('[data-item-id]');
      body.querySelectorAll('.drag-target').forEach((row) => {
        row.classList.remove('drag-target');
      });
      if (!target || target === dragged) return;
      target.classList.add('drag-target');
      const box = target.getBoundingClientRect();
      target.parentNode.insertBefore(
        dragged,
        event.clientY < box.top + box.height / 2 ? target : target.nextSibling
      );
    });

    body.addEventListener('drop', (event) => {
      if (!dragged) return;
      event.preventDefault();
      recordOrder();
    });

    body.addEventListener('dragend', () => {
      if (dragged) {
        dragged.classList.remove('dragging');
        recordOrder();
      }
      body.querySelectorAll('.drag-target').forEach((row) => {
        row.classList.remove('drag-target');
      });
      dragged = null;
    });

    body.addEventListener('keydown', (event) => {
      if (
        !event.target.matches('.drag-handle')
        || !['ArrowUp', 'ArrowDown'].includes(event.key)
      ) return;
      const row = event.target.closest('[data-item-id]');
      if (!row || !form) return;
      const sibling = event.key === 'ArrowUp'
        ? row.previousElementSibling
        : row.nextElementSibling;
      if (!sibling?.matches('[data-item-id]')) return;
      event.preventDefault();
      if (event.key === 'ArrowUp') {
        body.insertBefore(row, sibling);
      } else {
        body.insertBefore(sibling, row);
      }
      recordOrder();
      event.target.focus();
      const announcer = document.querySelector('[data-ui-announcer]');
      if (announcer) {
        const position = Array.from(body.querySelectorAll('[data-item-id]'))
          .indexOf(row) + 1;
        announcer.textContent = `Élément déplacé en position ${position}.`;
      }
    });
  });

  const blockIfDirty = (event) => {
    if (dirtyForms.size === 0) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    window.alert(warning);
  };

  document.querySelectorAll('[data-bs-toggle="tab"], [data-bs-toggle="pill"]')
    .forEach((control) => control.addEventListener('click', blockIfDirty, true));

  document.querySelectorAll('[data-external-action]')
    .forEach((form) => form.addEventListener('submit', blockIfDirty, true));

  window.addEventListener('beforeunload', (event) => {
    if (dirtyForms.size === 0) return;
    event.preventDefault();
    event.returnValue = warning;
  });
})();
