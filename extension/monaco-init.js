let editor = null;

require.config({
  paths: { 'vs': 'monaco/vs' }
});

// Listen for messages from parent window
window.addEventListener('message', (event) => {
  const { type, payload } = event.data;

  if (type === 'INIT_EDITOR') {
    initEditor(payload);
  } else if (type === 'GET_VALUE') {
    if (editor) {
      window.parent.postMessage({
        type: 'VALUE_RESPONSE',
        payload: { value: editor.getValue() }
      }, '*');
    }
  } else if (type === 'SET_VALUE') {
    if (editor) {
      editor.setValue(payload.value);
    }
  } else if (type === 'FOCUS') {
    if (editor) {
      editor.focus();
    }
  }
});

function initEditor(config) {
  require(['vs/editor/editor.main'], () => {
    editor = monaco.editor.create(document.getElementById('container'), {
      value: config.value || '',
      language: config.language || 'blade',
      theme: config.theme || 'vs-dark',
      automaticLayout: true,
      fontSize: config.fontSize || 14,
      lineHeight: config.lineHeight || 22,
      minimap: {
        enabled: config.minimap !== false
      },
      scrollBeyondLastLine: false,
      folding: true,
      lineNumbers: 'on',
      renderWhitespace: 'selection'
    });

    // Scroll to line if specified
    if (config.lineNumber) {
      editor.revealLineInCenter(config.lineNumber);
      editor.setPosition({ lineNumber: config.lineNumber, column: 1 });
    }

    editor.focus();

    // Notify parent that editor is ready
    window.parent.postMessage({
      type: 'EDITOR_READY'
    }, '*');

    // Set up keyboard shortcuts
    editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => {
      window.parent.postMessage({ type: 'SAVE' }, '*');
    });

    editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.Enter, () => {
      window.parent.postMessage({ type: 'SAVE' }, '*');
    });

    editor.addCommand(monaco.KeyCode.Escape, () => {
      window.parent.postMessage({ type: 'CANCEL' }, '*');
    });
  });
}
