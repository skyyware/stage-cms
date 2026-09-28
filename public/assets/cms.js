const editor = document.querySelector('[data-editor]');
if (editor) {
  const body = editor.querySelector('[data-body]');
  const title = editor.querySelector('#title');
  const slug = editor.querySelector('[data-slug]');
  const count = editor.querySelector('[data-word-count]');
  const state = editor.querySelector('[data-save-state]');
  const fields = [...editor.querySelectorAll('input[name], textarea[name], select[name]')];
  const initial = fields.map(field => field.value);
  let submitting = false;
  let slugEdited = slug.value !== '';
  slug.addEventListener('input', () => { slugEdited = true; });
  title.addEventListener('input', () => {
    if (!slugEdited) {
      slug.value = title.value.normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 120).replace(/-$/, '');
    }
  });
  const dirty = () => editor.dataset.unsaved === 'true' || fields.some((field, index) => field.value !== initial[index]);
  const update = () => {
    const words = body.value.trim() ? body.value.trim().split(/\s+/u).length : 0;
    count.textContent = words + (words === 1 ? ' word' : ' words') + ' · ' + Math.max(1, Math.ceil(words / 220)) + ' min read';
    const changed = dirty();
    state.textContent = changed ? 'Unsaved changes' : 'No unsaved changes';
    state.classList.toggle('unsaved', changed);
  };
  let scheduled = false;
  editor.addEventListener('input', () => {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => {
      scheduled = false;
      update();
    });
  });
  editor.addEventListener('submit', () => { submitting = true; });
  window.addEventListener('beforeunload', event => {
    if (dirty() && !submitting) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
  document.addEventListener('keydown', event => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
      event.preventDefault();
      editor.requestSubmit(editor.querySelector('[data-save]'));
    }
  });
  editor.querySelectorAll('[data-format]').forEach(button => button.addEventListener('click', () => {
    const start = body.selectionStart;
    const end = body.selectionEnd;
    const selected = body.value.slice(start, end);
    const wraps = { bold: ['**', '**'], italic: ['*', '*'], heading: ['\n## ', ''], link: ['[', '](https://example.com)'], list: ['\n- ', ''] };
    const [before, after] = wraps[button.dataset.format];
    const text = selected || (button.dataset.format === 'link' ? 'Link text' : 'Your text');
    body.setRangeText(before + text + after, start, end, 'end');
    body.focus();
    body.setSelectionRange(start + before.length, start + before.length + text.length);
    body.dispatchEvent(new Event('input', { bubbles: true }));
  }));
  update();
}
document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
  const original = button.textContent;
  try {
    await navigator.clipboard.writeText(button.dataset.copy);
    button.textContent = 'Copied';
  } catch {
    button.textContent = 'Select the text below to copy';
  }
  setTimeout(() => { button.textContent = original; }, 2500);
}));
