(() => {
  'use strict';

  const autoFocusTarget = document.querySelector('[data-auto-focus]');
  if (
    autoFocusTarget instanceof HTMLElement
    && (
      autoFocusTarget.getAttribute('role') === 'alert'
      || window.matchMedia('(min-width: 64rem)').matches
    )
  ) {
    autoFocusTarget.focus();
  }

  document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const input = document.getElementById(button.dataset.passwordToggle ?? '');
      if (!(input instanceof HTMLInputElement)) return;
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      button.setAttribute('aria-pressed', show ? 'true' : 'false');
      button.setAttribute(
        'aria-label',
        show ? 'Masquer le mot de passe' : 'Afficher le mot de passe',
      );
    });
  });

  const form = document.querySelector('[data-entry-form]');
  if (!form) return;

  const parseAmount = (value) => {
    const normalized = value.trim()
      .replaceAll("'", '')
      .replaceAll('’', '')
      .replaceAll(' ', '')
      .replace(',', '.');
    if (!/^\d+(?:\.\d{0,2})?$/.test(normalized)) return 0;
    return Math.round(Number(normalized) * 100);
  };
  const formatAmount = (cents) => (cents / 100).toFixed(2);
  const debitTotal = form.querySelector('[data-entry-debit-total]');
  const creditTotal = form.querySelector('[data-entry-credit-total]');
  const difference = form.querySelector('[data-entry-difference]');
  const state = form.querySelector('[data-entry-state]');

  const updateBalance = () => {
    const debit = Array.from(form.querySelectorAll('[data-entry-debit]'))
      .reduce((sum, input) => sum + parseAmount(input.value), 0);
    const credit = Array.from(form.querySelectorAll('[data-entry-credit]'))
      .reduce((sum, input) => sum + parseAmount(input.value), 0);
    const delta = debit - credit;
    debitTotal.textContent = formatAmount(debit);
    creditTotal.textContent = formatAmount(credit);
    difference.textContent = `${formatAmount(Math.abs(delta))} CHF`;
    state.textContent = delta === 0 ? '— équilibrée' : '— à équilibrer';
    state.classList.toggle('text-success', delta === 0);
    state.classList.toggle('text-danger', delta !== 0);
  };

  form.addEventListener('input', updateBalance);
  updateBalance();

  let dirty = false;
  form.addEventListener('input', () => { dirty = true; });
  form.addEventListener('change', () => { dirty = true; });
  form.addEventListener('submit', () => { dirty = false; });
  window.addEventListener('beforeunload', (event) => {
    if (!dirty) return;
    event.preventDefault();
    event.returnValue = '';
  });
})();
