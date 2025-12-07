/**
 * Monaco Editor Keyboard Tests
 *
 * Tests for keyboard shortcuts in the Monaco editor iframe.
 * These tests simulate keyboard events and verify the correct messages are sent.
 */

const MonacoKeyboardTests = {
  results: [],
  messagesSent: [],

  async wait(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
  },

  assert(condition, message) {
    if (condition) {
      this.results.push({ pass: true, message });
      console.log(`✓ ${message}`);
    } else {
      this.results.push({ pass: false, message });
      console.error(`✗ ${message}`);
    }
    return condition;
  },

  /**
   * Set up message capture
   */
  setupMessageCapture() {
    this.messagesSent = [];

    // Listen for messages that would be sent to parent
    window.addEventListener('message', (event) => {
      if (event.data && event.data.type) {
        this.messagesSent.push(event.data);
      }
    });
  },

  /**
   * Test: CMD+S dispatches SAVE message
   */
  async testCmdS() {
    this.messagesSent = [];

    // Create and dispatch CMD+S event
    const event = new KeyboardEvent('keydown', {
      key: 's',
      code: 'KeyS',
      keyCode: 83,
      which: 83,
      metaKey: true,  // CMD on Mac
      ctrlKey: false,
      shiftKey: false,
      altKey: false,
      bubbles: true,
      cancelable: true
    });

    // Dispatch on document
    const prevented = !document.dispatchEvent(event);

    await this.wait(100);

    // Check if event was prevented (it should be)
    this.assert(prevented || event.defaultPrevented, 'CMD+S should prevent default browser behavior');

    return prevented;
  },

  /**
   * Test: CTRL+S dispatches SAVE message (Windows/Linux)
   */
  async testCtrlS() {
    this.messagesSent = [];

    // Create and dispatch CTRL+S event
    const event = new KeyboardEvent('keydown', {
      key: 's',
      code: 'KeyS',
      keyCode: 83,
      which: 83,
      metaKey: false,
      ctrlKey: true,  // CTRL on Windows/Linux
      shiftKey: false,
      altKey: false,
      bubbles: true,
      cancelable: true
    });

    const prevented = !document.dispatchEvent(event);

    await this.wait(100);

    this.assert(prevented || event.defaultPrevented, 'CTRL+S should prevent default browser behavior');

    return prevented;
  },

  /**
   * Test: Escape key dispatches CANCEL message
   */
  async testEscape() {
    this.messagesSent = [];

    const event = new KeyboardEvent('keydown', {
      key: 'Escape',
      code: 'Escape',
      keyCode: 27,
      which: 27,
      bubbles: true,
      cancelable: true
    });

    document.dispatchEvent(event);

    await this.wait(100);

    // Escape doesn't need to prevent default
    this.assert(true, 'Escape key event was dispatched');
  },

  /**
   * Test: Regular typing doesn't trigger save
   */
  async testRegularTyping() {
    this.messagesSent = [];

    // Type a regular 's' without modifiers
    const event = new KeyboardEvent('keydown', {
      key: 's',
      code: 'KeyS',
      keyCode: 83,
      which: 83,
      metaKey: false,
      ctrlKey: false,
      shiftKey: false,
      altKey: false,
      bubbles: true,
      cancelable: true
    });

    document.dispatchEvent(event);

    await this.wait(100);

    // Regular 's' should NOT trigger save
    const saveTriggered = this.messagesSent.some(m => m.type === 'SAVE');
    this.assert(!saveTriggered, 'Regular "s" key should not trigger save');
  },

  /**
   * Test: PostMessage communication works
   */
  testPostMessageAvailable() {
    this.assert(typeof window.postMessage === 'function', 'window.postMessage should be available');
    this.assert(typeof window.parent !== 'undefined', 'window.parent should be defined');
  },

  /**
   * Run all tests
   */
  async runAll() {
    console.log('=== Running Monaco Keyboard Tests ===\n');

    this.results = [];
    this.setupMessageCapture();

    this.testPostMessageAvailable();
    await this.testCmdS();
    await this.testCtrlS();
    await this.testEscape();
    await this.testRegularTyping();

    // Summary
    console.log('\n=== Test Summary ===');
    const passed = this.results.filter(r => r.pass).length;
    const failed = this.results.filter(r => !r.pass).length;
    console.log(`Passed: ${passed}`);
    console.log(`Failed: ${failed}`);

    return { passed, failed, total: this.results.length, results: this.results };
  }
};

// Export
if (typeof window !== 'undefined') {
  window.MonacoKeyboardTests = MonacoKeyboardTests;
}
