let editor = null;
let lineDecorations = [];

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
    // Define a custom theme with line highlight color
    monaco.editor.defineTheme('refine-dark', {
      base: 'vs-dark',
      inherit: true,
      rules: [],
      colors: {
        'editor.background': '#1a1a1c',
        'editor.lineHighlightBackground': '#2a2a2c',
        'editorLineNumber.foreground': '#4a4a4c',
        'editorLineNumber.activeForeground': '#8e8e93',
        'editor.selectionBackground': '#3a3a3c',
        'editorGutter.background': '#1a1a1c',
      }
    });

    editor = monaco.editor.create(document.getElementById('container'), {
      value: config.value || '',
      language: config.language || 'html',
      theme: 'refine-dark',
      automaticLayout: true,
      fontSize: config.fontSize || 14,
      lineHeight: config.lineHeight || 24,
      fontFamily: "'SF Mono', 'Monaco', 'Menlo', 'Consolas', monospace",
      fontLigatures: true,
      minimap: {
        enabled: config.minimap !== false,
        scale: 1,
        showSlider: 'mouseover'
      },
      scrollBeyondLastLine: false,
      folding: true,
      lineNumbers: 'on',
      renderWhitespace: 'selection',
      smoothScrolling: true,
      cursorBlinking: 'smooth',
      cursorSmoothCaretAnimation: true,
      padding: {
        top: 12,
        bottom: 12
      },
      scrollbar: {
        verticalScrollbarSize: 8,
        horizontalScrollbarSize: 8,
        useShadows: false
      },
      overviewRulerBorder: false,
      hideCursorInOverviewRuler: true,
      renderLineHighlight: 'all',
      guides: {
        indentation: true,
        bracketPairs: false
      }
    });

    // Scroll to line if specified and highlight it
    if (config.lineNumber) {
      editor.revealLineInCenter(config.lineNumber);
      editor.setPosition({ lineNumber: config.lineNumber, column: 1 });

      // Add line decoration to highlight the target line
      highlightTargetLine(config.lineNumber);
    }

    editor.focus();

    // Notify parent that editor is ready
    window.parent.postMessage({
      type: 'EDITOR_READY'
    }, '*');

    // Set up keyboard shortcuts - CMD+S / CTRL+S to save
    // Use addAction to properly prevent default browser behavior
    editor.addAction({
      id: 'refine-save',
      label: 'Save File',
      keybindings: [
        monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS
      ],
      run: () => {
        window.parent.postMessage({ type: 'SAVE' }, '*');
      }
    });

    // Also add a document-level listener to catch CMD+S before it reaches the browser
    document.addEventListener('keydown', (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key === 's') {
        e.preventDefault();
        e.stopPropagation();
        window.parent.postMessage({ type: 'SAVE' }, '*');
      }
    }, true);

    // Escape to minimize
    editor.addCommand(monaco.KeyCode.Escape, () => {
      window.parent.postMessage({ type: 'MINIMIZE' }, '*');
    });
  });
}

// Highlight the target line with a subtle indicator
function highlightTargetLine(lineNumber) {
  if (!editor) return;

  // Create line decorations
  lineDecorations = editor.deltaDecorations(lineDecorations, [
    {
      range: new monaco.Range(lineNumber, 1, lineNumber, 1),
      options: {
        isWholeLine: true,
        className: 'refine-target-line',
        glyphMarginClassName: 'refine-target-glyph',
        overviewRuler: {
          color: '#3794ff',
          position: monaco.editor.OverviewRulerLane.Left
        }
      }
    }
  ]);
}

// Inject custom styles for line highlighting
const styleSheet = document.createElement('style');
styleSheet.textContent = `
  .refine-target-line {
    background: rgba(55, 148, 255, 0.08) !important;
    border-left: 2px solid #3794ff !important;
  }
  .refine-target-glyph {
    background: #3794ff;
    width: 3px !important;
    margin-left: 3px;
    border-radius: 1px;
  }
  .monaco-editor .margin {
    background: #1a1a1c !important;
  }
`;
document.head.appendChild(styleSheet);
